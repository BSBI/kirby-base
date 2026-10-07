<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\panel;

use BSBI\WebBase\forms\panel\KirbyResponseKeySource;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * Tests for KirbyResponseKeySource: the keys a form's stored responses (its
 * form_submission child pages) use.
 */
final class KirbyResponseKeySourceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-response-keys-' . uniqid());
    }

    public function testAFormWithoutResponsesHasNone(): void
    {
        $form = $this->form([]);
        $source = new KirbyResponseKeySource();

        $this->assertFalse($source->hasResponses($form));
        $this->assertSame([], $source->usedKeys($form, ['name']));
    }

    public function testUsedKeysAreThoseAnyResponseStores(): void
    {
        $form = $this->form([
            [['question' => 'Name', 'answer' => 'Ann', 'key' => 'name', 'column' => 'name']],
            [['question' => 'Where?', 'answer' => 'Here', 'key' => 'seen', 'column' => 'seen']],
            // Hand-written forms store items without a key
            [['question' => 'other', 'answer' => 'x']],
        ], withOtherChild: true);
        $source = new KirbyResponseKeySource();

        $this->assertTrue($source->hasResponses($form));
        $this->assertSame(['seen', 'name'], $source->usedKeys($form, ['seen', 'unused', 'name', 'other']));
    }

    /**
     * Returns a form page with one form_submission child per response.
     *
     * @param list<list<array<string, string>>> $responses Each response's stored items
     */
    private function form(array $responses, bool $withOtherChild = false): Page
    {
        $children = [];
        foreach ($responses as $n => $items) {
            $children[] = [
                'slug'     => 'response-' . $n,
                'template' => 'form_submission',
                'content'  => ['title' => 'Response ' . $n, 'submission' => Yaml::encode($items)],
            ];
        }
        if ($withOtherChild) {
            $children[] = [
                'slug'     => 'not-a-response',
                'template' => 'default',
                'content'  => ['submission' => Yaml::encode([['key' => 'other']])],
            ];
        }
        return Page::factory(['slug' => 'form-' . uniqid(), 'template' => 'form_builder', 'children' => $children]);
    }
}
