<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\about;

use BSBI\WebBase\forms\panel\PanelContent;
use BSBI\WebBase\helpers\ContentIndexRegistry;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Throwable;

/**
 * The kinds of page a form's feedback can be about (events, projects, …), from
 * the `forms.aboutKinds` option: kind => label, templates, and where to find
 * them, either a content index (`index`, with `titleColumn`/`dateColumn`) or a
 * parent page (`parent`), so choosing never walks the whole site. `dateField`
 * names the page's date field, if it has one, and `endDateField` its end
 * date; `details` lists other fields to show and fill from, field => label
 * (e.g. `['ledBy' => 'Led by']`).
 *
 * A form accepts the kinds ticked in its `aboutKinds` field ("Feedback is about").
 *
 * @phpstan-type Kind array{label: string, templates: list<string>, parent: string, index: string,
 *     titleColumn: string, dateColumn: string, dateField: string, endDateField: string,
 *     details: array<string, string>}
 * @phpstan-type Choice array{value: string, title: string, date: string, where: string, kind: string}
 */
final readonly class AboutKinds
{
    /** The most choices the picker pages through (newest first); a search finds older ones. */
    private const PICKER_MAX = 500;

    /** The most rows read from an index per kind. */
    private const INDEX_MAX = 500;

    public function __construct(private App $kirby)
    {
    }

    /**
     * Returns the configured kinds, kind => settings, with defaults filled in.
     *
     * @return array<string, Kind>
     */
    public function all(): array
    {
        $configured = $this->kirby->option('forms.aboutKinds', []);
        $kinds = [];
        foreach (is_array($configured) ? $configured : [] as $kind => $settings) {
            if (!is_string($kind) || !is_array($settings)) {
                continue;
            }
            $text = static fn(string $name): string => is_string($settings[$name] ?? null) ? $settings[$name] : '';
            $templates = array_values(array_filter((array) ($settings['templates'] ?? []), 'is_string'));
            if ($templates === []) {
                continue;
            }
            $kinds[$kind] = [
                'label'       => $text('label') !== '' ? $text('label') : ucfirst($kind),
                'templates'   => $templates,
                'parent'      => $text('parent'),
                'index'       => $text('index'),
                'titleColumn' => $text('titleColumn') !== '' ? $text('titleColumn') : 'title',
                'dateColumn'  => $text('dateColumn'),
                'dateField'   => $text('dateField'),
                'endDateField' => $text('endDateField'),
                'details'     => self::details($settings['details'] ?? []),
            ];
        }
        return $kinds;
    }

    /**
     * Returns the kinds a form accepts (its "Feedback is about" ticks), known ones only.
     *
     * @return list<string>
     */
    public function forForm(Page $form): array
    {
        $ticked = array_map('trim', explode(',', PanelContent::text($form->content(), 'aboutKinds')));
        return array_values(array_intersect($ticked, array_keys($this->all())));
    }

    /**
     * Returns the kind of a page, or null if it is none of them.
     */
    public function kindOf(Page $page): ?string
    {
        $template = $page->intendedTemplate()->name();
        foreach ($this->all() as $kind => $settings) {
            if (in_array($template, $settings['templates'], true)) {
                return $kind;
            }
        }
        return null;
    }

    /**
     * Returns a page's kind label (e.g. "Event"), or ''.
     */
    public function labelOf(Page $page): string
    {
        $kind = $this->kindOf($page);
        return $kind === null ? '' : $this->all()[$kind]['label'];
    }

    /**
     * Returns a page's date as Y-m-d, from its kind's date field, or ''.
     */
    public function dateOf(Page $page): string
    {
        return $this->dateIn($page, 'dateField');
    }

    /**
     * Returns a page's end date as Y-m-d, from its kind's end date field, or ''.
     */
    public function endDateOf(Page $page): string
    {
        return $this->dateIn($page, 'endDateField');
    }

    /**
     * Returns the page's details that have a value: field => label and value.
     *
     * @return array<string, array{label: string, value: string}>
     */
    public function detailsOf(Page $page): array
    {
        $kind = $this->kindOf($page);
        $details = [];
        foreach ($kind === null ? [] : $this->all()[$kind]['details'] as $field => $label) {
            $value = PanelContent::text($page->content(), $field);
            if ($value !== '') {
                $details[$field] = ['label' => $label, 'value' => $value];
            }
        }
        return $details;
    }

    /**
     * Returns the choices for a question's "Fill from the link": its title,
     * its date, and every kind's details, value => label.
     *
     * @return array<string, string>
     */
    public function fillOptions(): array
    {
        $options = ['title' => 'Its title', 'date' => 'Its date'];
        foreach ($this->all() as $settings) {
            $options += $settings['details'];
        }
        return $options;
    }

    /**
     * @param 'dateField'|'endDateField' $setting
     */
    private function dateIn(Page $page, string $setting): string
    {
        $kind = $this->kindOf($page);
        $field = $kind === null ? '' : $this->all()[$kind][$setting];
        if ($field === '') {
            return '';
        }
        $value = PanelContent::text($page->content(), $field);
        $time = $value === '' ? false : strtotime($value);
        return $time === false ? '' : date('Y-m-d', $time);
    }

    /**
     * Returns a kind's details setting as field => label, field names only
     * (they're also "Fill from the link" values).
     *
     * @return array<string, string>
     */
    private static function details(mixed $setting): array
    {
        $details = [];
        foreach (is_array($setting) ? $setting : [] as $field => $label) {
            if (is_string($field) && is_string($label) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $field) === 1
                && !in_array($field, ['title', 'date'], true)) {
                $details[$field] = $label;
            }
        }
        return $details;
    }

    /**
     * Returns the pages of the given kinds to choose from: dated ones newest
     * first, then by title; filtered by a search on the title; at most $limit.
     *
     * @param list<string> $kinds
     * @return list<Choice>
     */
    public function choices(array $kinds, string $search = '', int $limit = 50): array
    {
        $choices = [];
        foreach ($kinds as $kind) {
            $settings = $this->all()[$kind] ?? null;
            if ($settings === null) {
                continue;
            }
            foreach ($this->pagesOf($settings, $search) as $page) {
                $choices[] = [
                    'value' => $page->uuid()->toString(),
                    'title' => $page->title()->toString(),
                    'date'  => $this->dateOf($page),
                    'where' => $page->parent()?->title()->toString() ?? '',
                    'kind'  => $settings['label'],
                ];
            }
        }
        usort($choices, static fn(array $a, array $b): int => [$b['date'], $a['title']] <=> [$a['date'], $b['title']]);
        return array_slice($choices, 0, $limit);
    }

    /**
     * Returns one page of choices in the shape the panel's picker dialog
     * (k-models-dialog) reads: items with id (the page:// UUID), text and
     * info (date, where, and the kind when there's more than one), and the
     * pagination. Page 0 is read as 1.
     *
     * @param list<string> $kinds
     * @return array{data: list<array{id: string, text: string, info: string, image: array{icon: string}}>,
     *     pagination: array{page: int, limit: int, total: int}}
     */
    public function picker(array $kinds, string $search, int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $all = $this->choices($kinds, $search, self::PICKER_MAX);
        $data = [];
        foreach (array_slice($all, ($page - 1) * $limit, $limit) as $choice) {
            $data[] = [
                'id'    => $choice['value'],
                'text'  => $choice['title'],
                'info'  => implode(' · ', array_filter(
                    [$choice['date'], $choice['where'], count($kinds) > 1 ? $choice['kind'] : ''],
                    static fn(string $part): bool => $part !== ''
                )),
                'image' => ['icon' => $choice['date'] !== '' ? 'calendar' : 'page'],
            ];
        }
        return ['data' => $data, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => count($all)]];
    }

    /**
     * @param Kind $settings
     * @return list<Page>
     */
    private function pagesOf(array $settings, string $search): array
    {
        $matches = static fn(Page $page): bool => $search === ''
            || mb_stripos($page->title()->toString(), $search) !== false;

        if ($settings['index'] !== '') {
            try {
                $manager = ContentIndexRegistry::get($settings['index']);
                $query = $manager?->query();
                if ($query === null) {
                    return [];
                }
                if ($search !== '') {
                    // The index narrows to titles with a word starting with the search.
                    $query = $query->whereAnyWordStartsWith([$settings['titleColumn']], $search);
                }
                if ($settings['dateColumn'] !== '') {
                    $query = $query->orderBy($settings['dateColumn'], 'desc');
                }
                $pages = [];
                foreach ($query->limit(self::INDEX_MAX)->getPageIds() as $id) {
                    $page = $this->kirby->site()->findPageOrDraft((string) $id) ?? $this->kirby->site()->draft((string) $id);
                    if ($page instanceof Page && in_array($page->intendedTemplate()->name(), $settings['templates'], true) && $matches($page)) {
                        $pages[] = $page;
                    }
                }
                return $pages;
            } catch (Throwable) {
                return [];
            }
        }

        $parent = $settings['parent'] === '' ? null : $this->kirby->site()->find($settings['parent']);
        if (!$parent instanceof Page) {
            return [];
        }
        $pages = [];
        foreach ($parent->children()->listed() as $page) {
            if (in_array($page->intendedTemplate()->name(), $settings['templates'], true) && $matches($page)) {
                $pages[] = $page;
            }
        }
        return $pages;
    }
}
