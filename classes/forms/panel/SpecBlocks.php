<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use BSBI\WebBase\forms\FormFieldSpec;
use BSBI\WebBase\forms\ResolvedFormField;

/**
 * Turns a hand-written form's fields into panel field blocks: the inverse of
 * PanelFieldReader, for moving a PHP form definition into the panel.
 *
 * Fields are converted resolved, so a page's panel overrides are carried. The
 * block's field name is the PHP key, so stored responses and exports carry
 * on. Hand-written text could hold markup; the panel's is plain text, so tags
 * are dropped and entities decoded. A site-blocks field becomes display-only
 * text holding the site field's text as markdown.
 */
final class SpecBlocks
{
    /**
     * Returns the raw block data for one field, as stored in a blocks field.
     *
     * @param ResolvedFormField $field The field, resolved against its page
     * @param string            $seed  Makes the block id: the same seed and key give the same id
     * @return array{id: string, type: string, isHidden: false, content: array<string, string>}
     */
    public static function block(ResolvedFormField $field, string $seed): array
    {
        $id = self::id($seed, $field->name);

        if ($field->type === FormFieldSpec::TYPE_INFO || $field->type === FormFieldSpec::TYPE_SITE_BLOCKS) {
            $text = $field->type === FormFieldSpec::TYPE_SITE_BLOCKS ? self::markdown($field->content) : $field->content;
            return ['id' => $id, 'type' => 'form-info', 'isHidden' => false, 'content' => ['text' => $text]];
        }

        $content = [
            'label'    => self::plain($field->label),
            'name'     => $field->name,
            'required' => $field->required ? 'true' : 'false',
            'help'     => self::plain($field->help),
        ];

        switch ($field->type) {
            case FormFieldSpec::TYPE_TEXTBOX:
                $content['inputType'] = $field->inputType;
                break;
            case FormFieldSpec::TYPE_CHECKBOX_GROUP:
            case FormFieldSpec::TYPE_RADIO_GROUP:
            case FormFieldSpec::TYPE_SELECT:
                $content['options'] = implode("\n", array_map(self::plain(...), $field->options));
                break;
            case FormFieldSpec::TYPE_LIKERT:
                $content['leftLabel']   = self::plain($field->leftLabel);
                $content['middleLabel'] = self::plain($field->middleLabel);
                $content['rightLabel']  = self::plain($field->rightLabel);
                $content['scaleMin']    = (string) $field->scaleMin;
                $content['scaleMax']    = (string) $field->scaleMax;
                break;
            case FormFieldSpec::TYPE_RATING_MATRIX:
                $content['rows']    = implode("\n", array_map(self::plain(...), $field->rows));
                $content['columns'] = implode("\n", array_map(self::plain(...), $field->columns));
                break;
        }

        $type = array_search($field->type, PanelFieldReader::BLOCK_TYPES, true);
        return ['id' => $id, 'type' => is_string($type) ? $type : 'form-textbox', 'isHidden' => false, 'content' => $content];
    }

    /**
     * Returns hand-written text as plain text: tags dropped, entities decoded.
     */
    private static function plain(string $text): string
    {
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Returns rendered blocks HTML as simple markdown: headings, paragraphs and
     * list items kept as such, other markup dropped.
     */
    private static function markdown(string $html): string
    {
        $text = (string) preg_replace_callback(
            '#<h([1-6])[^>]*>(.*?)</h\1>#is',
            static fn(array $m): string => "\n\n" . str_repeat('#', max(2, (int) $m[1])) . ' ' . trim(strip_tags($m[2])) . "\n\n",
            $html
        );
        $text = (string) preg_replace('#<li[^>]*>(.*?)</li>#is', "\n- $1", $text);
        $text = (string) preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = (string) preg_replace('#</(p|ul|ol|div|blockquote)>#i', "\n\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        // List items follow one another without blank lines.
        $text = (string) preg_replace("/(\n- [^\n]*)\n\n(?=- )/", "$1\n", $text);
        return trim($text);
    }

    /**
     * Returns a v4-shaped UUID made from the seed and key, so converting the
     * same form twice gives the same block ids (and generated keys).
     */
    private static function id(string $seed, string $key): string
    {
        $hex = md5($seed . '#' . $key);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-'
            . dechex(8 + (hexdec($hex[16]) % 4)) . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }
}
