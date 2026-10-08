<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

use BSBI\WebBase\forms\panel\KirbySectionPageResolver;
use BSBI\WebBase\forms\panel\PanelFormDefinition;
use BSBI\WebBase\helpers\ContentIndexRegistry;
use Closure;
use Kirby\Cms\App;
use Kirby\Cms\Page;

/**
 * The panel's analysis of one form type: its responses (from the
 * `form_submissions` index, so no page-tree walk), filtered to one form and
 * a date range if asked, summarised by FormSubmissionAnalyser.
 *
 * Each question's shape comes from the panel-built forms of that type, and
 * from any form whose "also save as" sections save responses of that type.
 * Questions in sections saved as `edi`, and every question of the `edi`
 * type, have their small counts hidden. The result is cached for ten minutes.
 *
 * @phpstan-import-type Shape from FormSubmissionAnalyser
 */
final readonly class FormAnalysis
{
    /** The form type whose questions are all EDI questions. */
    public const EDI_TYPE = 'edi';

    private const CACHE_MINUTES = 10;

    /** @var Closure(string): list<string> */
    private Closure $responseIds;

    /** @var Closure(): array<string, string> */
    private Closure $builtForms;

    /**
     * @param App                                $kirby
     * @param (Closure(string): list<string>)|null $responseIds Form type => its responses' page ids; default from the index
     * @param (Closure(): array<string, string>)|null $builtForms Panel-built form page id => its form type; default from the index
     */
    public function __construct(private App $kirby, ?Closure $responseIds = null, ?Closure $builtForms = null)
    {
        $this->responseIds = $responseIds ?? static fn(string $type): array => self::idsFromIndex('form_submissions', $type);
        $this->builtForms = $builtForms ?? static fn(): array => self::builtFormsFromIndex();
    }

    /**
     * Returns forType(), cached for ten minutes per type and filter.
     *
     * @return array<string, mixed>
     */
    public function cached(string $type, string $formId = '', string $from = '', string $to = '', bool $refresh = false): array
    {
        $cache = $this->kirby->cache('bsbi');
        $key = 'form-analysis-' . md5(implode('|', [$type, $formId, $from, $to]));
        $stored = $refresh ? null : $cache->get($key);
        if (is_array($stored)) {
            /** @var array<string, mixed> $stored */
            return $stored;
        }
        $result = $this->forType($type, $formId, $from, $to) + ['builtAt' => date('Y-m-d H:i')];
        $cache->set($key, $result, self::CACHE_MINUTES);
        return $result;
    }

    /**
     * Returns the analysis of a form type's responses, optionally only those
     * of one form (its page id) and between two dates (Y-m-d, inclusive),
     * plus the forms with responses of that type, to filter by.
     *
     * @return array<string, mixed>
     */
    public function forType(string $type, string $formId = '', string $from = '', string $to = ''): array
    {
        $records = [];
        $forms = [];
        foreach (($this->responseIds)($type) as $id) {
            $response = $this->find($id);
            if (!$response instanceof Page) {
                continue;
            }
            $parent = $response->parent();
            if ($parent instanceof Page) {
                $forms[$parent->id()] = $parent->title()->toString();
            }
            $record = FormSubmissionExporter::record($response);
            $day = substr($record['date'], 0, 10);
            if (
                ($formId !== '' && $parent?->id() !== $formId)
                || ($from !== '' && $day < $from)
                || ($to !== '' && $day > $to)
            ) {
                continue;
            }
            $records[] = $record;
        }
        usort($records, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
        ksort($forms);

        [$shapes, $ediColumns] = $this->shapes($type);
        $analyser = new FormSubmissionAnalyser($shapes, $ediColumns, $type === self::EDI_TYPE);

        return ['type' => $type, 'formId' => $formId, 'from' => $from, 'to' => $to]
            + $analyser->analyse((new FormSubmissionExporter())->table($records))
            + ['forms' => array_map(
                static fn(string $id, string $title): array => ['id' => $id, 'title' => $title],
                array_keys($forms),
                $forms
            )];
    }

    /**
     * Returns each column's shape for a form type, from the panel-built forms
     * of that type and the "also save as" sections of that type, and the
     * columns that are EDI questions.
     *
     * @return array{0: array<string, Shape>, 1: list<string>}
     */
    private function shapes(string $type): array
    {
        $shapes = [];
        $edi = [];
        $resolver = new KirbySectionPageResolver($this->kirby);
        foreach (($this->builtForms)() as $formId => $formType) {
            $form = $this->find($formId);
            if (!$form instanceof Page) {
                continue;
            }
            $definition = new PanelFormDefinition($form, $formType, $resolver);
            $copies = $definition->copySectionKeys();
            if ($formType !== $type && !isset($copies[$type])) {
                continue;
            }
            $only = $formType === $type ? null : $copies[$type];
            $columns = $definition->getSubmissionColumns();
            foreach ($definition->getFields($form) as $field) {
                if (!isset($columns[$field->name]) || ($only !== null && !in_array($field->name, $only, true))) {
                    continue;
                }
                $id = 'c:' . $columns[$field->name]['column'];
                $shape = self::shapeOf($field);
                if ($shape !== null) {
                    $shapes[$id] ??= $shape;
                }
                if (in_array($field->name, $copies[self::EDI_TYPE] ?? [], true)) {
                    $edi[$id] = true;
                }
            }
        }
        return [$shapes, array_keys($edi)];
    }

    /**
     * Returns a field's shape for the analyser, or null for display-only text.
     *
     * @return Shape|null
     */
    private static function shapeOf(ResolvedFormField $field): ?array
    {
        $plain = static fn(string $text): string => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return match ($field->type) {
            FormFieldSpec::TYPE_RADIO_GROUP, FormFieldSpec::TYPE_SELECT => ['kind' => 'choice', 'options' => array_values(array_map($plain, $field->options))],
            FormFieldSpec::TYPE_CHECKBOX_GROUP => ['kind' => 'multi', 'options' => array_values(array_map($plain, $field->options))],
            FormFieldSpec::TYPE_LIKERT => [
                'kind'       => 'likert',
                'scaleMin'   => $field->scaleMin,
                'scaleMax'   => $field->scaleMax,
                'leftLabel'  => $plain($field->leftLabel),
                'rightLabel' => $plain($field->rightLabel),
            ],
            FormFieldSpec::TYPE_RATING_MATRIX => [
                'kind'    => 'grid',
                'rows'    => array_combine(
                    array_map(static fn(string $row): string => (string) preg_replace('/[^a-z0-9]+/', '_', strtolower($plain($row))), $field->rows),
                    array_map($plain, $field->rows)
                ),
                'columns' => array_values(array_map($plain, $field->columns)),
            ],
            FormFieldSpec::TYPE_TEXTBOX => ['kind' => $field->inputType === 'date' ? 'date' : 'text'],
            FormFieldSpec::TYPE_TEXTAREA => ['kind' => 'text'],
            default => null,
        };
    }

    /**
     * Returns the page or draft with the id, at any depth: findPageOrDraft()
     * misses a draft inside a published page, and draft() walks both.
     */
    private function find(string $id): ?Page
    {
        return $this->kirby->site()->findPageOrDraft($id) ?? $this->kirby->site()->draft($id);
    }

    /**
     * Returns the page ids of a form type's responses, from the index
     * ("(untyped)" for responses without a type).
     *
     * @return list<string>
     */
    private static function idsFromIndex(string $index, string $type): array
    {
        $manager = ContentIndexRegistry::get($index);
        if ($manager === null) {
            return [];
        }
        $formType = $type === FormSubmissionExporter::UNTYPED ? '' : $type;
        return array_values(array_filter(
            $manager->query()->where('form_type', $formType)->getPageIds(),
            'is_string'
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function builtFormsFromIndex(): array
    {
        $manager = ContentIndexRegistry::get('form_builders');
        if ($manager === null) {
            return [];
        }
        $forms = [];
        foreach ($manager->query()->get() as $row) {
            if (is_string($row['page_id'] ?? null)) {
                $forms[$row['page_id']] = is_string($row['form_type'] ?? null) ? $row['form_type'] : '';
            }
        }
        return $forms;
    }
}
