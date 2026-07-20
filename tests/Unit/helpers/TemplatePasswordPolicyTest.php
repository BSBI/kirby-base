<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\TemplatePasswordPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TemplatePasswordPolicy: resolving a template-wide password from the
 * 'templatePasswords' config option.
 *
 * This lets whole classes of auto-generated pages (e.g. customer order pages,
 * which have no per-page password field) be gated behind one shared, temporary
 * password without editing every page's content.
 */
final class TemplatePasswordPolicyTest extends TestCase
{
    public function testReturnsPasswordForConfiguredTemplate(): void
    {
        self::assertSame(
            'secret',
            TemplatePasswordPolicy::passwordForTemplate('order', ['order' => 'secret'])
        );
    }

    public function testReturnsEmptyStringForUnconfiguredTemplate(): void
    {
        self::assertSame(
            '',
            TemplatePasswordPolicy::passwordForTemplate('default', ['order' => 'secret'])
        );
    }

    public function testReturnsEmptyStringForEmptyConfig(): void
    {
        self::assertSame('', TemplatePasswordPolicy::passwordForTemplate('order', []));
    }

    public function testReturnsEmptyStringWhenConfigIsNotAnArray(): void
    {
        self::assertSame('', TemplatePasswordPolicy::passwordForTemplate('order', 'nonsense'));
    }

    public function testReturnsEmptyStringWhenPasswordValueIsNotAString(): void
    {
        self::assertSame('', TemplatePasswordPolicy::passwordForTemplate('order', ['order' => ['array']]));
    }
}
