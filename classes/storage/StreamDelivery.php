<?php

declare(strict_types=1);

namespace BSBI\WebBase\storage;

/**
 * Serves a stream of known size to the browser with HTTP Range support, in
 * chunks, ending the request. Kirby's Response holds its body as a string,
 * which a large document or archive must avoid; and a browser that loses a
 * long download resumes it with a Range request rather than starting again.
 *
 * plan() is pure and tested; send() is the few lines around it.
 */
final readonly class StreamDelivery
{
    private const int CHUNK = 1024 * 1024;

    /**
     * The status, headers and byte range for a request.
     *
     * @param int $size The full size of the resource
     * @param string|null $rangeHeader The request's Range header, if any
     * @param string $filename The name to offer
     * @param string $mime The media type
     * @param bool $download Force a download (attachment) rather than inline display
     * @return array{status: int, headers: array<string, string>, from: int, to: int} `to` is inclusive; a 416 has from > to
     */
    public function plan(int $size, ?string $rangeHeader, string $filename, string $mime, bool $download): array
    {
        $safeName = str_replace(['"', "\r", "\n"], '', $filename);
        $headers = [
            'Content-Type'        => $mime,
            'Content-Disposition' => ($download ? 'attachment' : 'inline') . '; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName),
            'Accept-Ranges'       => 'bytes',
            'Cache-Control'       => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $from = 0;
        $to = $size - 1;
        $status = 200;
        if ($rangeHeader !== null && preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $m) === 1 && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $from = max(0, $size - (int) $m[2]);
            } else {
                $from = (int) $m[1];
                $to = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
            }
            if ($from > $to || $from >= $size) {
                return ['status' => 416, 'headers' => ['Content-Range' => 'bytes */' . $size], 'from' => 1, 'to' => 0];
            }
            $status = 206;
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $from, $to, $size);
        }
        $headers['Content-Length'] = (string) ($to - $from + 1);
        return ['status' => $status, 'headers' => $headers, 'from' => $from, 'to' => $to];
    }

    /**
     * Send the stream and end the request.
     *
     * @param resource $stream A readable, seekable-or-not stream positioned at byte 0
     * @param int $size Its full size
     */
    public function send($stream, int $size, string $filename, string $mime, bool $download, ?string $rangeHeader = null): never
    {
        $requestRange = $_SERVER['HTTP_RANGE'] ?? null;
        $plan = $this->plan($size, $rangeHeader ?? (is_string($requestRange) ? $requestRange : null), $filename, $mime, $download);
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($plan['status']);
        foreach ($plan['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($plan['status'] === 416) {
            fclose($stream);
            exit;
        }
        $this->skip($stream, $plan['from']);
        $left = $plan['to'] - $plan['from'] + 1;
        while ($left > 0 && !feof($stream) && !connection_aborted()) {
            $chunk = fread($stream, min(self::CHUNK, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            flush();
            $left -= strlen($chunk);
        }
        fclose($stream);
        exit;
    }

    /**
     * Move to a byte offset: seek where the stream allows, read past otherwise
     * (an S3 stream is not seekable).
     *
     * @param resource $stream
     */
    private function skip($stream, int $offset): void
    {
        if ($offset <= 0) {
            return;
        }
        if (stream_get_meta_data($stream)['seekable'] && fseek($stream, $offset) === 0) {
            return;
        }
        $left = $offset;
        while ($left > 0 && !feof($stream)) {
            $chunk = fread($stream, min(self::CHUNK, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $left -= strlen($chunk);
        }
    }
}
