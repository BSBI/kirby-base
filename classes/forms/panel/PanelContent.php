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
     * Returns the visible blocks in a blocks field, or an empty collection if
     * the field is blank or unreadable (reported to $problems).
     *
     * Kirby gives a block stored without an id a random one on every load,
     * which would make a generated field key change on every request. Such
     * blocks (only ever written by scripts; the panel always stores ids) get
     * an id derived from the owner, field and position instead, stable until
     * the panel saves real ids, and are reported so an editor does that before
     * responses arrive.
     *
     * @param Content      $content  Page or block content
     * @param string       $name     Blocks field name
     * @param FormProblems $problems Receives read failures and missing ids
     * @param string       $ownerId  Stable id of the content's owner (page or block id)
     * @param string       $where    Editor-facing name of the content's owner
     */
    public static function blocks(
        Content $content,
        string $name,
        FormProblems $problems,
        string $ownerId,
        string $where
    ): Blocks
    {
        $field = self::field($content, $name);
        if ($field->isEmpty()) {
            return new Blocks([]);
        }

        try {
            $data = Blocks::parse($field->toString());
            $missingIds = false;
            foreach ($data as $index => $block) {
                if (is_array($block) && (!isset($block['id']) || !is_string($block['id']) || $block['id'] === '')) {
                    $data[$index]['id'] = self::fallbackId($ownerId . '#' . $name, (int) $index);
                    $missingIds = true;
                }
            }
            $blocks = Blocks::factory($data, ['parent' => $field->parent(), 'field' => $field]);
        } catch (\Throwable) {
            $problems->add(sprintf('%s: the "%s" content could not be read; it has been left out.', $where, $name));
            return new Blocks([]);
        }

        if ($missingIds) {
            $problems->add(sprintf(
                '%s has content stored without an id, so generated field names in it are provisional; '
                . 'open it in the panel and save it before the form takes responses.',
                $where
            ));
        }

        return $blocks->filter('isHidden', false);
    }

    /**
     * Returns a stable stand-in block id, UUID-shaped so keyFor() reads it the
     * same way as a real one.
     *
     * @param string $fieldPath Owner id and blocks field name
     * @param int    $index     Position in the field
     */
    private static function fallbackId(string $fieldPath, int $index): string
    {
        $hex = md5($fieldPath . '#' . $index);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
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
