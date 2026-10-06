<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

use Closure;
use Kirby\Toolkit\Str;

/**
 * Names a new form_submission page after the time it was submitted, adding
 * -1, -2… when another submission to the same form already has that name.
 *
 * The check uses the slug Kirby will actually store (lower case, dots as
 * hyphens). The title keeps the readable timestamp.
 */
final class FormSubmissionSlug
{
    /**
     * Returns the slug and title for a submission made at $timestamp.
     *
     * @param string                 $timestamp E.g. date('M-j-H.i.s')
     * @param Closure(string): bool  $exists    True if a page with that slug is already under the form
     * @return array{slug: string, title: string}
     */
    public static function next(string $timestamp, Closure $exists): array
    {
        $base = Str::slug($timestamp);
        $suffix = '';
        $counter = 0;

        while ($exists($base . $suffix)) {
            $counter++;
            $suffix = '-' . $counter;
        }

        return ['slug' => $base . $suffix, 'title' => 'Submission: ' . $timestamp . $suffix];
    }
}
