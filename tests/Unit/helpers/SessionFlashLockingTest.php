<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\SessionFlash;
use Kirby\Session\FileSessionStore;
use Kirby\Session\Session;
use Kirby\Session\Sessions;
use PHPUnit\Framework\TestCase;

/**
 * Counts the exclusive locks Kirby's session store is asked to take.
 *
 * An exclusive lock is exactly what "the session is about to be rewritten" means,
 * so counting them is a direct measurement of the property under test rather than
 * a proxy for it.
 */
final class LockCountingSessionStore extends FileSessionStore
{
    public int $exclusiveLocks = 0;

    public function lock(int $expiryTime, string $id): void
    {
        $this->exclusiveLocks++;
        parent::lock($expiryTime, $id);
    }
}

/**
 * End-to-end verification that SessionFlash keeps the common path lock-free,
 * against a real Kirby Session and a real file-backed session store.
 *
 * SessionFlashTest pins SessionFlash's own contract against a mocked SessionData.
 * That is necessary but not sufficient: the whole point of the class rests on a
 * claim about Kirby's behaviour — that SessionData::get() does not enter write
 * mode while SessionData::pull() does — and a mock cannot prove that. This test
 * measures it.
 *
 * @see SessionFlash
 */
final class SessionFlashLockingTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/session-flash-' . uniqid();
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    /**
     * Stores a value under an existing session token, exactly as a redirecting
     * request would, and hands back the token for a later "request" to read.
     *
     * @param array<string, mixed> $values Session values to store
     * @return string The session token
     */
    private function seedSession(array $values): string
    {
        $sessions = new Sessions(new LockCountingSessionStore($this->directory), [
            'mode'       => 'manual',
            'gcInterval' => false,
        ]);

        $session = $sessions->create(['mode' => 'manual']);

        foreach ($values as $key => $value) {
            $session->data()->set($key, $value);
        }

        $session->ensureToken();
        $token = $session->token();
        $session->commit();

        return $token;
    }

    /**
     * Opens the seeded session as a fresh request would, and returns the session
     * together with the store counting its locks.
     *
     * @return array{0: Session, 1: LockCountingSessionStore}
     */
    private function openAsNewRequest(string $token): array
    {
        $store    = new LockCountingSessionStore($this->directory);
        $sessions = new Sessions($store, ['mode' => 'manual', 'gcInterval' => false]);
        $session  = $sessions->get($token, 'manual');

        // Ignore any locking done while opening the session; what is being measured
        // is what the flash read itself costs.
        $store->exclusiveLocks = 0;

        return [$session, $store];
    }

    public function testReadingAnAbsentFlashValueTakesNoExclusiveLock(): void
    {
        [$session, $store] = $this->openAsNewRequest($this->seedSession([]));

        self::assertNull((new SessionFlash($session->data()))->pull('actionStatus'));
        self::assertSame(0, $store->exclusiveLocks, 'A page render with no flash value must not lock the session file');

        $session->commit();
    }

    /**
     * The counterpart: Kirby's own pull() locks even when there is nothing to pull.
     * This is the behaviour SessionFlash exists to avoid, so it is asserted rather
     * than assumed — if a future Kirby stops doing this, this test fails and
     * SessionFlash can be retired.
     */
    public function testKirbysOwnPullLocksEvenWhenThereIsNothingToPull(): void
    {
        [$session, $store] = $this->openAsNewRequest($this->seedSession([]));

        self::assertNull($session->pull('actionStatus'));
        self::assertGreaterThan(
            0,
            $store->exclusiveLocks,
            'Kirby pull() no longer enters write mode unconditionally — SessionFlash may no longer be needed'
        );

        $session->commit();
    }

    public function testReadingAPresentFlashValueLocksOnceAndClearsIt(): void
    {
        $token = $this->seedSession(['actionStatus' => 'saved']);

        [$session, $store] = $this->openAsNewRequest($token);

        self::assertSame('saved', (new SessionFlash($session->data()))->pull('actionStatus'));
        self::assertGreaterThan(0, $store->exclusiveLocks, 'Clearing a flash value must take the write lock');

        $session->commit();

        // A subsequent request must not see it again.
        [$next] = $this->openAsNewRequest($token);
        self::assertNull((new SessionFlash($next->data()))->pull('actionStatus'));
        $next->commit();
    }
}
