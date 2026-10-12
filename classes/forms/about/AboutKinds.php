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
 * names the page's date field, if it has one.
 *
 * A form accepts the kinds ticked in its `aboutKinds` field ("Feedback is about").
 *
 * @phpstan-type Kind array{label: string, templates: list<string>, parent: string, index: string,
 *     titleColumn: string, dateColumn: string, dateField: string}
 * @phpstan-type Choice array{value: string, title: string, date: string, where: string, kind: string}
 */
final readonly class AboutKinds
{
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
     * Returns a page's date as Y-m-d, from its kind's date field, or ''.
     */
    public function dateOf(Page $page): string
    {
        $kind = $this->kindOf($page);
        $field = $kind === null ? '' : $this->all()[$kind]['dateField'];
        if ($field === '') {
            return '';
        }
        $value = PanelContent::text($page->content(), $field);
        $time = $value === '' ? false : strtotime($value);
        return $time === false ? '' : date('Y-m-d', $time);
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
                foreach ($query->limit(200)->getPageIds() as $id) {
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
