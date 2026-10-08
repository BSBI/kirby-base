<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

use Kirby\Cms\App;

/**
 * Session slots in the visitor's Kirby session.
 */
final readonly class KirbySessionSlots implements SessionSlots
{
    public function __construct(private App $kirby)
    {
    }

    /**
     * @inheritDoc
     */
    public function get(string $name): ?string
    {
        $value = $this->kirby->session()->data()->get($name);
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @inheritDoc
     */
    public function set(string $name, string $value): void
    {
        $session = $this->kirby->session();
        $session->ensureToken();
        $session->data()->set($name, $value);
    }
}
