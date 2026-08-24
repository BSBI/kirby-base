<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SessionFlash;
use Kirby\Session\SessionData;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SessionFlash: reading a one-shot session value without putting the
 * session into write mode when there is nothing to read.
 *
 * This exists because Kirby's own SessionData::pull() calls prepareForWriting()
 * unconditionally, before it has even looked at whether the key is set. Every
 * caller that speculatively pulls a flash value on each page render therefore
 * takes an exclusive lock on the session file and rewrites it on every request —
 * which both serialises a user's concurrent requests and widens the window on a
 * Kirby race that fatals with "Cannot write to session ..., because it is not
 * locked" when a concurrent request has regenerated the session token.
 *
 * The write-avoidance is the load-bearing property here, so it is asserted
 * directly: remove() must not be reached when the key is absent.
 */
final class SessionFlashTest extends TestCase
{
    public function testReturnsNullAndNeverWritesWhenTheKeyIsAbsent(): void
    {
        $data = $this->createMock(SessionData::class);
        $data->method('get')->with('actionStatus')->willReturn(null);
        $data->expects($this->never())->method('remove');

        self::assertNull((new SessionFlash($data))->pull('actionStatus'));
    }

    public function testReturnsTheValueAndClearsItWhenTheKeyIsPresent(): void
    {
        $data = $this->createMock(SessionData::class);
        $data->method('get')->with('actionStatus')->willReturn('saved');
        $data->expects($this->once())->method('remove')->with('actionStatus');

        self::assertSame('saved', (new SessionFlash($data))->pull('actionStatus'));
    }

    /**
     * A stored value may legitimately be falsy. Kirby's pull() would clear it and
     * the old call sites then discarded it via a truthiness test; the contract here
     * is that anything actually stored is returned and cleared, and only a genuinely
     * absent key is left alone.
     */
    public function testFalsyStoredValuesAreStillReturnedAndCleared(): void
    {
        $data = $this->createMock(SessionData::class);
        $data->method('get')->with('count')->willReturn(0);
        $data->expects($this->once())->method('remove')->with('count');

        self::assertSame(0, (new SessionFlash($data))->pull('count'));
    }

    public function testObjectsSurviveTheRoundTrip(): void
    {
        $value = new \stdClass();

        $data = $this->createMock(SessionData::class);
        $data->method('get')->with('exception')->willReturn($value);
        $data->expects($this->once())->method('remove')->with('exception');

        self::assertSame($value, (new SessionFlash($data))->pull('exception'));
    }
}
