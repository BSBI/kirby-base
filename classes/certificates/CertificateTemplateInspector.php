<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PdfReader;
use Throwable;

/**
 * Reports the text already present in a certificate design.
 *
 * A design is meant to arrive with its variable text removed, leaving us to merge
 * it per recipient. When that text is left in by mistake the result is worse than
 * cosmetic: every recipient's certificate carries somebody else's name, extractable
 * even where it is not visible. Checking is cheap, so it is done rather than assumed.
 *
 * @package BSBI\WebBase
 */
final readonly class CertificateTemplateInspector
{
    /**
     * Extract the text drawn anywhere in a certificate design.
     *
     * Every stream in the document is scanned, not just the pages' own content
     * streams. Design tools routinely nest the entire page inside a form XObject —
     * both sample designs do — so reading only the page stream reports no text for
     * a page that is full of it. Over-reporting is the safe direction of error for
     * a check whose job is to notice text that should have been removed.
     *
     * @param string $path Absolute path to the PDF to inspect
     * @return string[] The text strings found, in the order they are drawn
     * @throws CertificateException If the PDF cannot be read
     */
    public function extractText(string $path): array
    {
        if ($path === '' || !is_readable($path)) {
            throw new CertificateException('Certificate design could not be read: ' . $path);
        }

        $contents = (string)file_get_contents($path);
        $found = [];

        if (preg_match_all('/stream\r?\n(.*?)endstream/s', $contents, $matches) === 0) {
            return [];
        }

        foreach ($matches[1] as $stream) {
            $decoded = $this->inflate($stream);
            if ($decoded === null) {
                continue;
            }
            foreach ($this->findShownText($decoded) as $text) {
                $found[] = $text;
            }
        }

        return $found;
    }

    /**
     * Decompress a stream, or return it unchanged when it is not compressed.
     *
     * @param string $stream The raw stream bytes
     * @return string|null The usable bytes, or null when the stream cannot be read
     *                     as text-bearing content
     */
    private function inflate(string $stream): ?string
    {
        $inflated = @gzuncompress($stream);
        if ($inflated === false) {
            $inflated = @gzinflate($stream);
        }

        if ($inflated !== false) {
            return $inflated;
        }

        // Decompression is attempted first and the raw bytes used only as a
        // fallback. Testing the raw stream for operator names up front looks
        // cheaper but misreads compressed data, whose bytes contain "Tj" often
        // enough to report text on a page that has none.
        return str_contains($stream, 'Tj') || str_contains($stream, 'TJ') ? $stream : null;
    }

    /**
     * The number of pages in a certificate design.
     *
     * @param string $path Absolute path to the PDF to inspect
     * @return int The page count
     * @throws CertificateException If the PDF cannot be read or parsed
     */
    public function getPageCount(string $path): int
    {
        if ($path === '' || !is_readable($path)) {
            throw new CertificateException('Certificate design could not be read: ' . $path);
        }

        try {
            return (new PdfReader(new PdfParser(StreamReader::createByFile($path))))->getPageCount();
        } catch (Throwable $e) {
            throw new CertificateException(
                'Could not parse certificate design "' . $path . '": ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Whether a design contains any drawn text at all.
     *
     * @param string $path Absolute path to the PDF to inspect
     * @return bool True when text was found
     * @throws CertificateException If the PDF cannot be read
     */
    public function hasText(string $path): bool
    {
        return $this->extractText($path) !== [];
    }

    /**
     * The PDF version a design declares in its header.
     *
     * @param string $path Absolute path to the PDF to inspect
     * @return string The version, e.g. '1.4', or an empty string when absent
     * @throws CertificateException If the PDF cannot be read
     */
    public function getPdfVersion(string $path): string
    {
        if ($path === '' || !is_readable($path)) {
            throw new CertificateException('Certificate design could not be read: ' . $path);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new CertificateException('Certificate design could not be read: ' . $path);
        }

        $header = (string)fread($handle, 16);
        fclose($handle);

        return preg_match('/^%PDF-(\d+\.\d+)/', $header, $matches) === 1 ? $matches[1] : '';
    }

    /**
     * Check that a design can actually be used to produce certificates.
     *
     * Intended to run when a design is chosen, not when certificates are being
     * generated. An unusable design discovered at configuration time costs an
     * administrator a minute; the same design discovered part-way through a run
     * costs a mailout.
     *
     * @param string $path Absolute path to the PDF to check
     * @param int $page The page intended for use, one-based
     * @return void
     * @throws CertificateException If the design cannot be used, with a message
     *                              describing what to do about it
     */
    public function assertUsable(string $path, int $page = 1): void
    {
        if ($path === '' || !is_readable($path)) {
            throw new CertificateException('Certificate design could not be read: ' . $path);
        }

        $this->assertObjectsAreReadable($path);

        $pageCount = $this->getPageCount($path);

        if ($page < 1 || $page > $pageCount) {
            throw new CertificateException(sprintf(
                'Certificate design "%s" has %d page(s), so page %d cannot be used.',
                basename($path),
                $pageCount,
                $page
            ));
        }
    }

    /**
     * Check that a design's object definitions can be read.
     *
     * PDF 1.5 introduced compressed object streams, which the free PDF parser
     * cannot decode. Designs exported by most current design tools use them by
     * default; the ones seen so far happen not to, which is easy to mistake for
     * general compatibility.
     *
     * @param string $path Absolute path to the PDF to check
     * @return void
     * @throws CertificateException If the design uses compressed object streams
     */
    private function assertObjectsAreReadable(string $path): void
    {
        if (!str_contains((string)file_get_contents($path), '/ObjStm')) {
            return;
        }

        $version = $this->getPdfVersion($path);

        throw new CertificateException(sprintf(
            'Certificate design "%s"%s uses compressed object streams, which cannot be read. '
            . 'Re-export the design as PDF 1.4.',
            basename($path),
            $version !== '' ? ' (PDF ' . $version . ')' : ''
        ));
    }

    /**
     * The names of the fonts a design references.
     *
     * This is the most practical check for variable text left in by mistake. A
     * certificate normally sets its recipient's name in a display face used
     * nowhere else, so that face appearing in the design is strong evidence the
     * name is still present — far more reliable than reading the text itself,
     * which subset fonts encode by glyph index rather than by character.
     *
     * @param string $path Absolute path to the PDF to inspect
     * @return string[] The font names found, with any subset prefix removed, sorted
     * @throws CertificateException If the PDF cannot be read or parsed
     */
    public function getFontNames(string $path): array
    {
        if ($path === '' || !is_readable($path)) {
            throw new CertificateException('Certificate design could not be read: ' . $path);
        }

        // Font names are read from the raw bytes, which only works while the
        // object definitions are uncompressed. A design using object streams
        // would yield no matches and so be reported as using no fonts at all —
        // a silent all-clear from the very check meant to catch a name left in.
        $this->assertObjectsAreReadable($path);

        $contents = (string)file_get_contents($path);

        if (preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9+\-,#]+)/', $contents, $matches) === 0) {
            return [];
        }

        $names = array_map(
            // Subset fonts are prefixed with six capitals and a plus, e.g.
            // "CAAAAA+ApricotsRegular" — the prefix is an arbitrary tag, not part
            // of the font's identity, so it is dropped for comparison.
            static fn(string $name): string => (string)preg_replace('/^[A-Z]{6}\+/', '', $name),
            $matches[1]
        );

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Pull the operands of the text-showing operators out of a content stream.
     *
     * The strings are returned raw, exactly as encoded in the PDF. Designs that
     * subset their fonts encode text by glyph index rather than character, so the
     * result is reliable for answering "is there text here" but not for reading it.
     *
     * @param string $content The decoded page content stream
     * @return string[] The text strings found
     */
    private function findShownText(string $content): array
    {
        $found = [];

        // Tj and ' take a single string; TJ takes an array of strings and kerning
        // numbers. Both literal (...) and hex <...> string forms are matched.
        $pattern = '/\((?:[^()\\\\]|\\\\.)*\)|<[0-9a-fA-F\s]*>/';

        if (preg_match_all('/(?:\[(.*?)\]\s*TJ|(\((?:[^()\\\\]|\\\\.)*\))\s*(?:Tj|\'|"))/s', $content, $matches)) {
            foreach ($matches[0] as $match) {
                if (preg_match_all($pattern, $match, $strings) === 0) {
                    continue;
                }
                foreach ($strings[0] as $string) {
                    // Deliberately not trimmed: subset fonts encode text by glyph
                    // index, and those bytes routinely include NUL and TAB, which
                    // PHP's default trim charlist would strip to nothing — making
                    // a page full of text look empty.
                    $decoded = $this->decodeString($string);
                    if ($decoded !== '') {
                        $found[] = $decoded;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Decode a PDF string operand into its raw bytes.
     *
     * @param string $string The string operand, including its delimiters
     * @return string The decoded bytes
     */
    private function decodeString(string $string): string
    {
        if (str_starts_with($string, '<')) {
            $hex = preg_replace('/\s+/', '', trim($string, '<>')) ?? '';
            if (strlen($hex) % 2 === 1) {
                $hex .= '0';
            }
            return (string)hex2bin($hex);
        }

        $literal = substr($string, 1, -1);

        return (string)preg_replace_callback(
            '/\\\\(n|r|t|b|f|\(|\)|\\\\|[0-7]{1,3})/',
            static function (array $match): string {
                return match ($match[1]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\b",
                    'f' => "\f",
                    '(' => '(',
                    ')' => ')',
                    '\\' => '\\',
                    default => chr((int)octdec($match[1])),
                };
            },
            $literal
        );
    }
}
