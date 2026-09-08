<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\UuidResolver;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Uuid\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UuidResolver (bsbi-web#732).
 *
 * Kirby resolves an unknown UUID by walking the whole site index and never
 * caches a miss, so a dangling file:// or page:// reference costs a full walk
 * on every request. The resolver remembers misses for a short time, logs each
 * one once, and short-circuits while the entry lives.
 */
final class UuidResolverTest extends TestCase
{
    private static App $kirby;
    private static string $logsDir;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::bootWithContent(
            dirname(__DIR__, 2) . '/fixtures/uuid-content',
            'kirby-base-uuid-resolver',
            ['options' => ['cacheName' => 'bsbi', 'cache' => ['bsbi' => true, 'uuid' => true]]]
        );
        self::$logsDir = self::$kirby->root('logs');
    }

    protected function setUp(): void
    {
        $this->resolver()->forgetMisses();
        self::$kirby->cache('uuid')->flush();
        @unlink(self::$logsDir . '/uuid-misses.log');
    }

    private function resolver(): UuidResolver
    {
        return new UuidResolver(self::$kirby);
    }

    private function logLines(): array
    {
        $path = self::$logsDir . '/uuid-misses.log';
        return is_file($path) ? array_values(array_filter(explode(PHP_EOL, (string)file_get_contents($path)))) : [];
    }

    public function testResolvesAnExistingFileAndPage(): void
    {
        $file = $this->resolver()->file('file://fileaaaaaaaaaaaa');
        self::assertInstanceOf(File::class, $file);
        self::assertSame('home/pic.svg', $file->id());

        $page = $this->resolver()->page('page://pagebbbbbbbbbbbb');
        self::assertInstanceOf(Page::class, $page);
        self::assertSame('other', $page->id());

        self::assertFalse($this->resolver()->isKnownMiss('file://fileaaaaaaaaaaaa'));
        self::assertSame([], $this->logLines());
    }

    public function testUnknownUuidIsNullRecordedAndLoggedOnce(): void
    {
        $resolver = $this->resolver();

        self::assertNull($resolver->file('file://nosuchfile000000'));
        self::assertTrue($resolver->isKnownMiss('file://nosuchfile000000'));
        self::assertCount(1, $this->logLines());
        self::assertStringContainsString('file://nosuchfile000000', $this->logLines()[0]);

        self::assertNull($resolver->file('file://nosuchfile000000'));
        self::assertNull($this->resolver()->file('file://nosuchfile000000'), 'a fresh instance reads the shared miss list');
        self::assertCount(1, $this->logLines(), 'a repeated miss is not logged again');
    }

    public function testKnownMissShortCircuitsWithoutLookingUp(): void
    {
        // The file exists but is not in the uuid cache; if the resolver consulted the
        // index it would find it. A recorded miss must win while it lives.
        $resolver = $this->resolver();
        $resolver->recordMiss('file://fileaaaaaaaaaaaa');

        self::assertNull($resolver->file('file://fileaaaaaaaaaaaa'));

        $resolver->forgetMisses();
        self::assertInstanceOf(File::class, $resolver->file('file://fileaaaaaaaaaaaa'));
    }

    public function testCachedUuidWinsOverAStaleMiss(): void
    {
        // Once Kirby's own cache knows the model, the miss list is irrelevant.
        $resolver = $this->resolver();
        $resolver->recordMiss('page://pagebbbbbbbbbbbb');
        Uuid::for('page://pagebbbbbbbbbbbb')->populate();

        self::assertInstanceOf(Page::class, $resolver->page('page://pagebbbbbbbbbbbb'));
        self::assertFalse($resolver->isKnownMiss('page://pagebbbbbbbbbbbb'), 'a hit clears its own miss entry');
    }

    public function testExpiredMissIsResolvedAgain(): void
    {
        $resolver = $this->resolver();
        $resolver->recordMiss('file://fileaaaaaaaaaaaa', ttlSeconds: -1);

        self::assertFalse($resolver->isKnownMiss('file://fileaaaaaaaaaaaa'));
        self::assertInstanceOf(File::class, $resolver->file('file://fileaaaaaaaaaaaa'));
    }

    public function testPlainIdsBypassTheMissList(): void
    {
        $resolver = $this->resolver();
        self::assertInstanceOf(File::class, $resolver->file('home/pic.svg'));
        self::assertInstanceOf(Page::class, $resolver->page('other'));
        self::assertNull($resolver->file('home/nothing.svg'));
        self::assertFalse($resolver->isKnownMiss('home/nothing.svg'));
    }

    public function testFieldHelpersSkipDanglingReferences(): void
    {
        $home = self::$kirby->page('home');
        $resolver = $this->resolver();

        self::assertSame('home/pic.svg', $resolver->fileFromField($home->content()->get('goodimage'))?->id());
        self::assertNull($resolver->fileFromField($home->content()->get('badimage')));
        self::assertSame(['home/pic.svg'], $resolver->filesFromField($home->content()->get('mixedimages'))->keys());
        self::assertSame('other', $resolver->pageFromField($home->content()->get('goodpage'))?->id());
        self::assertNull($resolver->pageFromField($home->content()->get('badpage')));
        self::assertNull($resolver->fileFromField($home->content()->get('doesnotexist')));

        self::assertTrue($resolver->isKnownMiss('file://nosuchfile000000'));
        self::assertTrue($resolver->isKnownMiss('page://nosuchpage000000'));
    }
}
