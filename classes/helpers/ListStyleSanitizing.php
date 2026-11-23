<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Sane\Html;

/**
 * Lets the lettered and roman list styles (bsbi-web#786) through Kirby's HTML
 * sanitizer.
 *
 * The styles are stored as `<ol type="a">` etc. Since Kirby 5.6 the list field
 * sanitizes its value as the writer field always has, and Sane's HTML handler
 * allows no attributes on `<ol>`, so a save in the Panel dropped the style. This
 * adds `type` to the attributes `<ol>` may keep; everything else is unchanged.
 */
final class ListStyleSanitizing
{
    /**
     * Adds `type` to the `<ol>` attributes Sane's HTML handler keeps. Safe to call
     * more than once.
     */
    public static function allow(): void
    {
        $current = Html::$allowedTags['ol'] ?? true;
        $attrs = is_array($current) ? $current : [];
        if (!in_array('type', $attrs, true)) {
            $attrs[] = 'type';
        }
        Html::$allowedTags['ol'] = $attrs;
    }
}
