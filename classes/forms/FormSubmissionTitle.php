<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

use BSBI\WebBase\forms\about\AboutSubject;
use DateTimeImmutable;

/**
 * What a response's title names after its submission time, so a form's
 * responses can be told apart in the panel: "Submission: Oct-20-14.03.12 —
 * Spring botany walk (12 May 2026)".
 *
 * The subject is what an about link named (its title and date), or else the
 * respondent's answers to the questions an about link would fill with the
 * title and date (fillFromKeys()), as typed. A Y-m-d date (a link's, or a
 * date picker's answer) reads "12 May 2026". Only what is known is named;
 * with nothing, the title is the time alone.
 */
final class FormSubmissionTitle
{
    /** Between the submission time and what the response is about. */
    public const SEPARATOR = ' — ';

    /** The longest description, in characters, ending "…" when cut. */
    public const MAX_LENGTH = 80;

    /**
     * Returns what the response is about, e.g. "Spring botany walk (12 May
     * 2026)", or blank when nothing is known.
     *
     * @param list<array{question: string, answer: string, key?: string, column?: string}> $items
     * @param array<string, string> $fillFrom Key => 'title' or 'date' (fillFromKeys())
     * @param AboutSubject|null     $about    The about link's subject, if any
     */
    public static function describe(array $items, array $fillFrom, ?AboutSubject $about): string
    {
        if ($about !== null) {
            $title = $about->title;
            $date = self::formatDate($about->date);
        } else {
            $answers = [];
            foreach ($items as $item) {
                $what = $fillFrom[$item['key'] ?? ''] ?? '';
                if ($what !== '' && !isset($answers[$what])) {
                    $answers[$what] = $item['answer'];
                }
            }
            $title = $answers['title'] ?? '';
            $date = self::formatDate(trim($answers['date'] ?? ''));
        }

        $title = self::clean($title);
        $date = self::clean($date);
        $described = match (true) {
            $title !== '' && $date !== '' => $title . ' (' . $date . ')',
            default                       => $title . $date,
        };
        return mb_strlen($described) > self::MAX_LENGTH
            ? rtrim(mb_substr($described, 0, self::MAX_LENGTH - 1)) . '…'
            : $described;
    }

    /**
     * Returns $title naming $described after its submission time, replacing
     * what it named before; a blank $described leaves the time alone.
     *
     * @param string $title     A response title, e.g. "Submission: Oct-20-14.03.12"
     * @param string $described What the response is about (describe())
     */
    public static function with(string $title, string $described): string
    {
        $time = explode(self::SEPARATOR, $title, 2)[0];
        return $described !== '' ? $time . self::SEPARATOR . $described : $time;
    }

    /**
     * Returns a Y-m-d date as "12 May 2026"; anything else as given.
     */
    private static function formatDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $parsed->format('j F Y') : $date;
    }

    /**
     * Returns the text on one line, with runs of white space as one space and
     * no control or format characters (such as a right-to-left override), so
     * a respondent's answer can't make the title read misleadingly.
     */
    private static function clean(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return trim((string) preg_replace('/\p{C}/u', '', $text));
    }
}
