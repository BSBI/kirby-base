<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

use BSBI\WebBase\forms\panel\PanelContent;
use BSBI\WebBase\helpers\ContentIndexRegistry;
use BSBI\WebBase\helpers\ContentTemplateScanner;
use Closure;
use Kirby\Cms\App;
use Kirby\Cms\Page;

/**
 * Lists every form on the site for the panel's Forms page: pages using a form
 * template (found by content file name, without loading the page tree), and
 * any other page that has responses, so nothing is missed. Each with where it
 * is, its kind, form type, status, responses, latest response and content
 * folder (for copying a form between servers).
 *
 * Responses come from the `form_submissions` index (each response's parent).
 * The list is cached for ten minutes; cached(refresh: true) rebuilds it.
 */
final readonly class FormsInUse
{
    private const CACHE_KEY = 'forms-in-use';

    private const CACHE_MINUTES = 10;

    /** @var Closure(): array<string, array{count: int, last: string}> */
    private Closure $responses;

    /**
     * @param App                   $kirby
     * @param array<string, string> $kinds     Form template => its kind, as shown
     * @param Closure(): array<string, array{count: int, last: string}>|null $responses
     *        Form page id => its responses' count and latest date; default from the index
     */
    public function __construct(private App $kirby, private array $kinds, ?Closure $responses = null)
    {
        $this->responses = $responses ?? static fn(): array => self::responsesFromIndex();
    }

    /**
     * Returns the kinds for the site: the `forms.inUseTemplates` option
     * (template => kind), over every builder template ("Built in the panel")
     * and every template with extra sections ("Hand-written, with extra
     * sections").
     *
     * @return array<string, string>
     */
    public static function kindsFor(App $kirby): array
    {
        $kinds = array_fill_keys(FormBuilderOptions::builderTemplates($kirby), 'Built in the panel')
            + array_fill_keys(FormBuilderOptions::extraSectionsTemplates($kirby), 'Hand-written, with extra sections');
        $configured = $kirby->option('forms.inUseTemplates', []);
        foreach (is_array($configured) ? $configured : [] as $template => $kind) {
            if (is_string($template) && is_string($kind) && $kind !== '') {
                $kinds[$template] = $kind;
            }
        }
        return $kinds;
    }

    /**
     * Returns the cached list, building it if missing or asked to.
     *
     * @return array{rows: list<array<string, mixed>>, builtAt: string}
     */
    public function cached(bool $refresh = false): array
    {
        $cache = $this->kirby->cache('bsbi');
        $stored = $refresh ? null : $cache->get(self::CACHE_KEY);
        if (is_array($stored) && isset($stored['rows'], $stored['builtAt'])) {
            /** @var array{rows: list<array<string, mixed>>, builtAt: string} $stored */
            return $stored;
        }
        $list = ['rows' => $this->rows(), 'builtAt' => date('Y-m-d H:i')];
        $cache->set(self::CACHE_KEY, $list, self::CACHE_MINUTES);
        return $list;
    }

    /**
     * Returns one row per form, those with the latest responses first, then
     * the rest by id.
     *
     * @return list<array{id: string, title: string, where: string, kind: string, formType: string,
     *     status: string, responses: int, lastResponse: string, folder: string, panelUrl: string}>
     */
    public function rows(): array
    {
        $contentRoot = (string) $this->kirby->root('content');
        $responses = ($this->responses)();
        $ids = (new ContentTemplateScanner($contentRoot))->pageIds(array_keys($this->kinds));
        $ids = array_values(array_unique([...$ids, ...array_keys($responses)]));

        $rows = [];
        foreach ($ids as $id) {
            // findPageOrDraft() misses a draft inside a published page; draft() walks both.
            $page = $this->kirby->site()->findPageOrDraft($id) ?? $this->kirby->site()->draft($id);
            if (!$page instanceof Page) {
                continue;
            }
            $template = $page->intendedTemplate()->name();
            $stats = $responses[$id] ?? ['count' => 0, 'last' => ''];
            $rows[] = [
                'id'           => $page->id(),
                'title'        => $page->title()->toString(),
                'where'        => implode(' › ', $page->parents()->flip()->toArray(static fn(Page $p): string => $p->title()->toString())),
                'kind'         => $this->kinds[$template] ?? 'Other page with responses (' . $template . ')',
                'formType'     => PanelContent::text($page->content(), 'form_type'),
                'status'       => $page->isDraft() ? 'draft' : ($page->isListed() ? 'listed' : 'unlisted'),
                'responses'    => $stats['count'],
                'lastResponse' => $stats['last'] !== '' ? substr($stats['last'], 0, 10) : '',
                'folder'       => ltrim(substr($page->root(), strlen($contentRoot)), '/'),
                'panelUrl'     => $page->panel()->url(),
            ];
        }

        usort($rows, static fn(array $a, array $b): int => [$b['lastResponse'], $a['id']] <=> [$a['lastResponse'], $b['id']]);
        return $rows;
    }

    /**
     * Returns each form page's response count and latest response date, from
     * the `form_submissions` index (a response's form is its parent page).
     *
     * @return array<string, array{count: int, last: string}>
     */
    private static function responsesFromIndex(): array
    {
        $manager = ContentIndexRegistry::get('form_submissions');
        if ($manager === null) {
            return [];
        }
        $stats = [];
        foreach ($manager->query()->get() as $row) {
            $pageId = is_string($row['page_id'] ?? null) ? $row['page_id'] : '';
            $form = dirname($pageId);
            if ($pageId === '' || $form === '.') {
                continue;
            }
            $at = is_string($row['submitted_at'] ?? null) ? $row['submitted_at'] : '';
            $stats[$form]['count'] = ($stats[$form]['count'] ?? 0) + 1;
            $stats[$form]['last'] = max($stats[$form]['last'] ?? '', $at);
        }
        return $stats;
    }
}
