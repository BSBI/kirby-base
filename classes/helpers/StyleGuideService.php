<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Http\Remote;

/**
 * Checks a Kirby page's text content against a site-specific style guide
 * using an AI API and returns an editor-facing compliance report.
 *
 * The style guide is read from `site/config/style-guide.md`.
 * Configure the provider via the `styleguide.provider` option ('gemini' or 'claude').
 * Gemini: requires `gemini.apiKey`; optionally `gemini.model` (default: gemini-2.0-flash).
 * Claude: requires `claude.apiKey`; optionally `claude.model` (default: claude-sonnet-4-6).
 */
class StyleGuideService
{
    private const string GEMINI_API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const string CLAUDE_API_URL  = 'https://api.anthropic.com/v1/messages';

    /** @var string[] Blueprint field types whose raw value is plain text */
    private const array TEXT_FIELD_TYPES = [
        'text', 'textarea', 'slug', 'email', 'url', 'link',
        'tags', 'select', 'radio', 'multiselect', 'checkboxes',
    ];

    /** @var string[] Blueprint field types whose value is an HTML string */
    private const array WRITER_FIELD_TYPES = ['writer'];

    /** @var string[] Blueprint field types whose value is a Kirby blocks JSON blob */
    private const array BLOCKS_FIELD_TYPES = ['blocks'];

    /**
     * Run a style guide check against the given page.
     *
     * Resolves the page (including drafts under draft parents), extracts all
     * text content from blueprint fields, and queries the configured AI provider
     * for a compliance report against the style guide at `site/config/style-guide.md`.
     *
     * Provider is selected via the `styleguide.provider` config option ('gemini' or 'claude').
     *
     * @param string $pageId Kirby page ID (slash-separated path segments)
     * @return array{report: string}|array{error: string}
     */
    public static function check(string $pageId): array
    {
        if ($pageId === '') {
            return ['error' => 'No page ID provided.'];
        }

        $page = self::resolvePage($pageId);
        // A page the user may not open is not found, as in the panel (bsbi-web#830).
        if ($page === null || !$page->isAccessible()) {
            return ['error' => 'Page not found: ' . $pageId];
        }

        $styleGuide = self::loadStyleGuide();
        if ($styleGuide === '') {
            return ['error' => 'Style guide not found. Add a style guide to site/config/style-guide.md'];
        }

        $pageContent = self::extractPageText($page);
        if ($pageContent === '') {
            return ['error' => 'No text content found on this page to check.'];
        }

        $prompt   = self::buildPrompt($page->title()->toString(), $pageContent, $styleGuide);
        $provider = (string) kirby()->option('styleguide.provider', 'gemini');

        if ($provider === 'claude') {
            $apiKey = (string) kirby()->option('claude.apiKey', '');
            if ($apiKey === '') {
                return ['error' => 'Claude API key not configured. Add claude.apiKey to your site config.'];
            }
            return self::callClaudeApi($prompt, $apiKey);
        }

        $apiKey = (string) kirby()->option('gemini.apiKey', '');
        if ($apiKey === '' || $apiKey === 'YOUR_GEMINI_API_KEY_HERE') {
            return ['error' => 'Gemini API key not configured. Add gemini.apiKey to your site config.'];
        }
        return self::callGeminiApi($prompt, $apiKey);
    }

    /**
     * Resolve a page ID to a Kirby Page, walking path segments to support
     * pages that live under draft parents (which kirby()->page() cannot find).
     *
     * @param string $pageId
     * @return Page|null
     */
    private static function resolvePage(string $pageId): ?Page
    {
        $page = kirby()->page($pageId);
        if ($page !== null) {
            return $page;
        }

        $parts   = explode('/', $pageId);
        $current = kirby()->site()->children()->findBy('slug', $parts[0])
                   ?? kirby()->site()->drafts()->findBy('slug', $parts[0]);

        for ($i = 1; $i < count($parts) && $current !== null; $i++) {
            $current = $current->children()->findBy('slug', $parts[$i])
                       ?? $current->drafts()->findBy('slug', $parts[$i]);
        }

        return $current instanceof Page ? $current : null;
    }

    /**
     * Load the style guide markdown content from `site/config/style-guide.md`.
     *
     * @return string Empty string if the file cannot be read.
     */
    private static function loadStyleGuide(): string
    {
        $path = kirby()->root('config') . '/style-guide.md';

        if (!is_file($path)) {
            KirbyBaseHelper::writeToLogFile('errors', 'StyleGuideService: style guide not found at ' . $path);
            return '';
        }

        $contents = file_get_contents($path);
        return $contents !== false ? $contents : '';
    }

    /**
     * Extract all human-readable text content from the page's blueprint fields.
     *
     * Processes text, textarea, writer, and blocks fields. Non-textual fields
     * (dates, toggles, images, files, pages, numbers, etc.) are skipped.
     *
     * @param Page $page
     * @return string Concatenated content with field labels as section headings.
     */
    private static function extractPageText(Page $page): string
    {
        $parts           = [];
        $blueprintFields = $page->blueprint()->fields();

        foreach ($blueprintFields as $fieldName => $fieldDef) {
            $type = $fieldDef['type'] ?? '';

            try {
                $field = $page->content()->get($fieldName);
            } catch (\Throwable) {
                continue;
            }

            if (!$field instanceof Field || $field->isEmpty()) {
                continue;
            }

            $label = (string) ($fieldDef['label'] ?? ucfirst((string) $fieldName));
            $text  = '';

            if (in_array($type, self::TEXT_FIELD_TYPES, true)) {
                $text = $field->toString();
            } elseif (in_array($type, self::WRITER_FIELD_TYPES, true)) {
                $text = strip_tags((string) $field->value());
            } elseif (in_array($type, self::BLOCKS_FIELD_TYPES, true)) {
                try {
                    /** @noinspection PhpUndefinedMethodInspection */
                    $text = strip_tags((string) $field->toBlocks()->toHtml());
                } catch (\Throwable $e) {
                    KirbyBaseHelper::writeToLogFile(
                        'errors',
                        'StyleGuideService: failed to render blocks field "' . $fieldName . '": ' . $e->getMessage()
                    );
                }
            }

            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
            if ($text !== '') {
                $parts[] = "### $label\n$text";
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build the prompt combining the page title, extracted content, and style guide.
     *
     * @param string $pageTitle
     * @param string $pageContent Extracted plain text from the page fields
     * @param string $styleGuide  Full style guide markdown content
     * @return string
     */
    private static function buildPrompt(string $pageTitle, string $pageContent, string $styleGuide): string
    {
        return <<<PROMPT
You are a professional content editor reviewing website content against the organisation's Style Guide.

## Style Guide

{$styleGuide}

---

## Page Content to Check

**Page title:** {$pageTitle}

{$pageContent}

---

## Your Task

Review the page content above against the Style Guide and produce a clear, actionable report for the editor.

Guidelines for your report:
- Group findings by Style Guide category (e.g. Tone, Punctuation, Plant Names, Abbreviations, etc.)
- For each issue: state the problem clearly, quote the offending text, cite the relevant style guide rule, and suggest a correction
- Skip any category that has no issues
- End with a 2–3 sentence overall summary
- If the content is fully compliant, say so clearly and briefly

Be concise and practical. Focus on real issues, not nitpicks. Editors need actionable feedback.
PROMPT;
    }

    /**
     * Call the Gemini 2.0 Flash API with the given prompt and return the report.
     *
     * @param string $prompt
     * @param string $apiKey
     * @return array{report: string}|array{error: string}
     */
    private static function callGeminiApi(string $prompt, string $apiKey): array
    {
        $model = (string) kirby()->option('gemini.model', 'gemini-2.0-flash');
        $url   = self::GEMINI_API_BASE . $model . ':generateContent?key=' . urlencode($apiKey);
        $body = json_encode([
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
        ]);

        try {
            $response = Remote::request($url, [
                'method'  => 'POST',
                'headers' => ['Content-Type' => 'application/json'],
                'data'    => $body,
                'timeout' => 60,
            ]);
        } catch (\Throwable $e) {
            KirbyBaseHelper::writeToLogFile('errors', 'StyleGuideService: HTTP request failed: ' . $e->getMessage());
            return ['error' => 'Failed to connect to Gemini API: ' . $e->getMessage()];
        }

        if ($response->code() === 429) {
            KirbyBaseHelper::writeToLogFile(
                'errors',
                'StyleGuideService: Gemini API quota exceeded (HTTP 429) — ' . $response->content()
            );
            return ['error' => 'Gemini API quota exceeded. Enable billing on your Google AI project, or set gemini.model in your config to a model with a free-tier allowance (e.g. gemini-1.5-flash).'];
        }

        if ($response->code() !== 200) {
            KirbyBaseHelper::writeToLogFile(
                'errors',
                'StyleGuideService: Gemini API returned HTTP ' . $response->code() . ' — ' . $response->content()
            );
            return ['error' => 'Gemini API error (HTTP ' . $response->code() . '). Check the server error log.'];
        }

        $rawContent = $response->content();
        if ($rawContent === null) {
            return ['error' => 'Empty response from Gemini API.'];
        }

        /** @var array<mixed>|null $data */
        $data = json_decode($rawContent, true);
        if (!is_array($data)) {
            return ['error' => 'Invalid JSON response from Gemini API.'];
        }

        $candidates = $data['candidates'] ?? null;
        $text       = is_array($candidates)
            && is_array($candidates[0] ?? null)
            && is_array($candidates[0]['content'] ?? null)
            && is_array($candidates[0]['content']['parts'] ?? null)
            && is_array($candidates[0]['content']['parts'][0] ?? null)
            ? ($candidates[0]['content']['parts'][0]['text'] ?? null)
            : null;

        if (!is_string($text)) {
            return ['error' => 'Unexpected Gemini API response format. The model may have declined the request.'];
        }

        return ['report' => $text];
    }

    /**
     * Call the Claude API with the given prompt and return the report.
     *
     * Uses the `claude.model` config option (default: claude-sonnet-4-6).
     *
     * @param string $prompt
     * @param string $apiKey Anthropic API key
     * @return array{report: string}|array{error: string}
     */
    private static function callClaudeApi(string $prompt, string $apiKey): array
    {
        $model = (string) kirby()->option('claude.model', 'claude-sonnet-4-6');
        $body  = json_encode([
            'model'      => $model,
            'max_tokens' => 2048,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        try {
            $response = Remote::request(self::CLAUDE_API_URL, [
                'method'  => 'POST',
                'headers' => [
                    'Content-Type'      => 'application/json',
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ],
                'data'    => $body,
                'timeout' => 60,
            ]);
        } catch (\Throwable $e) {
            KirbyBaseHelper::writeToLogFile('errors', 'StyleGuideService: Claude HTTP request failed: ' . $e->getMessage());
            return ['error' => 'Failed to connect to Claude API: ' . $e->getMessage()];
        }

        if ($response->code() !== 200) {
            KirbyBaseHelper::writeToLogFile(
                'errors',
                'StyleGuideService: Claude API returned HTTP ' . $response->code() . ' — ' . $response->content()
            );
            return ['error' => 'Claude API error (HTTP ' . $response->code() . '). Check the server error log.'];
        }

        $rawContent = $response->content();
        if ($rawContent === null) {
            return ['error' => 'Empty response from Claude API.'];
        }

        /** @var array<mixed>|null $data */
        $data = json_decode($rawContent, true);
        if (!is_array($data)) {
            return ['error' => 'Invalid JSON response from Claude API.'];
        }

        $content = $data['content'] ?? null;
        $text    = is_array($content)
            && is_array($content[0] ?? null)
            && ($content[0]['type'] ?? null) === 'text'
            ? ($content[0]['text'] ?? null)
            : null;

        if (!is_string($text)) {
            return ['error' => 'Unexpected Claude API response format.'];
        }

        return ['report' => $text];
    }
}
