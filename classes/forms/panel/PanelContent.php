<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use Kirby\Cms\Blocks;
use Kirby\Content\Content;
use Kirby\Content\Field;

/**
 * Typed access to page and block content for the panel-form readers.
 */
final class PanelContent
{
    /**
     * Returns the named field. Content::get() also returns an array when
     * called without a key, which this rules out.
     *
     * @param Content $content Page or block content
     * @param string  $name    Field name
     */
    public static function field(Content $content, string $name): Field
    {
        $field = $content->get($name);
        if (!$field instanceof Field) {
            throw new \LogicException("Content::get('{$name}') did not return a Field");
        }
        return $field;
    }

    /**
     * Returns the named field's value, trimmed.
     *
     * @param Content $content Page or block content
     * @param string  $name    Field name
     */
    public static function text(Content $content, string $name): string
    {
        return trim(self::field($content, $name)->toString());
    }

    /**
     * Returns the blocks in a blocks field, or an empty collection if blank.
     *
     * @param Content $content Page or block content
     * @param string  $name    Blocks field name
     */
    public static function blocks(Content $content, string $name): Blocks
    {
        $field = self::field($content, $name);
        return $field->isEmpty() ? new Blocks([]) : $field->toBlocks();
    }

    /**
     * Returns the first reference stored in a pages field, or null if none.
     *
     * @param Content $content Page or block content
     * @param string  $name    Pages field name
     */
    public static function firstReference(Content $content, string $name): ?string
    {
        $field = self::field($content, $name);
        if ($field->isEmpty()) {
            return null;
        }
        foreach ($field->yaml() as $reference) {
            if (is_string($reference) && trim($reference) !== '') {
                return trim($reference);
            }
        }
        return null;
    }
}
