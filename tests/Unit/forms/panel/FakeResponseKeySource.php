<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\panel;

use BSBI\WebBase\forms\panel\ResponseKeySource;
use Kirby\Cms\Page;

/**
 * Test double: each form's stored responses are a fixed list of keys, by
 * form page id. Records which forms had their responses read.
 */
final class FakeResponseKeySource implements ResponseKeySource
{
    /** @var list<string> Ids of forms whose responses were read */
    public array $read = [];

    /**
     * @param array<string, list<string>> $keysByForm Form page id => keys its responses use
     */
    public function __construct(private readonly array $keysByForm)
    {
    }

    public function hasResponses(Page $form): bool
    {
        return isset($this->keysByForm[$form->id()]);
    }

    public function usedKeys(Page $form, array $keys): array
    {
        $this->read[] = $form->id();
        $used = $this->keysByForm[$form->id()] ?? [];
        return array_values(array_filter($keys, static fn(string $key): bool => in_array($key, $used, true)));
    }
}
