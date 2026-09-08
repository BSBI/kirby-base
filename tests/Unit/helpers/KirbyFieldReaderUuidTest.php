<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\KirbyFieldReader;
use BSBI\WebBase\helpers\KirbyRetrievalException;
use BSBI\WebBase\helpers\UuidResolver;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * KirbyFieldReader's file/page resolution goes through UuidResolver (bsbi-web#732):
 * a dangling reference yields null (or an exception where the return type demands a
 * model) and is recorded as a miss; intact references resolve as before.
 */
final class KirbyFieldReaderUuidTest extends TestCase
{
    private static App $kirby;
    private static KirbyFieldReader $reader;

    public static function setUpBeforeClass(): void
    {
        self::$kirby = KirbyTestEnvironment::bootWithContent(
            dirname(__DIR__, 2) . '/fixtures/uuid-content',
            'kirby-base-field-reader-uuid',
            ['options' => ['cacheName' => 'bsbi', 'cache' => ['bsbi' => true, 'uuid' => true]]]
        );
        self::$reader = new KirbyFieldReader(self::$kirby, self::$kirby->site());
    }

    protected function setUp(): void
    {
        (new UuidResolver(self::$kirby))->forgetMisses();
    }

    public function testPageFieldsResolveIntactReferencesAndSkipDanglingOnes(): void
    {
        $home = self::$kirby->page('home');

        self::assertInstanceOf(File::class, self::$reader->getPageFieldAsFile($home, 'goodimage'));
        self::assertNull(self::$reader->getPageFieldAsFile($home, 'badimage'));
        self::assertSame(['home/pic.svg'], self::$reader->getPageFieldAsFiles($home, 'mixedimages')->keys());
        self::assertInstanceOf(Page::class, self::$reader->getPageFieldAsFirstPage($home, 'goodpage'));
        self::assertNull(self::$reader->getPageFieldAsFirstPage($home, 'badpage'));
        self::assertSame([], self::$reader->getPageFieldAsPages($home, 'badpage')->keys());

        $resolver = new UuidResolver(self::$kirby);
        self::assertTrue($resolver->isKnownMiss('file://nosuchfile000000'));
        self::assertTrue($resolver->isKnownMiss('page://nosuchpage000000'));
    }

    public function testSiteFileFieldResolvesOrThrows(): void
    {
        self::assertInstanceOf(File::class, self::$reader->getSiteFieldAsFile('goodfile'));

        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getSiteFieldAsFile('badfile');
    }

    public function testStructureFieldsResolveIntactReferencesAndSkipDanglingOnes(): void
    {
        $cards = self::$kirby->page('home')->content()->get('cards')->toStructure();
        $good = $cards->first();
        $bad = $cards->last();

        self::assertInstanceOf(File::class, self::$reader->getStructureFieldAsFile($good, 'image'));
        self::assertSame(['home/pic.svg'], self::$reader->getStructureFieldAsFiles($good, 'images')->keys());
        self::assertInstanceOf(Page::class, self::$reader->getStructureFieldAsPage($good, 'link'));

        self::assertSame([], self::$reader->getStructureFieldAsFiles($bad, 'images')->keys());
        try {
            self::$reader->getStructureFieldAsFile($bad, 'image');
            self::fail('a dangling structure file reference should throw');
        } catch (KirbyRetrievalException) {
        }
        try {
            self::$reader->getStructureFieldAsPage($bad, 'link');
            self::fail('a dangling structure page reference should throw');
        } catch (KirbyRetrievalException) {
        }

        $resolver = new UuidResolver(self::$kirby);
        self::assertTrue($resolver->isKnownMiss('file://nosuchfile000000'));
        self::assertTrue($resolver->isKnownMiss('page://nosuchpage000000'));
    }

    public function testSiteFieldAsPageThrowsInsteadOfReturningNull(): void
    {
        // site.txt in the fixture holds `Danglingpage: - page://nosuchpage000000`
        $this->expectException(KirbyRetrievalException::class);
        self::$reader->getSiteFieldAsPage('danglingpage');
    }
}
