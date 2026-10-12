<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\about;

use BSBI\WebBase\forms\about\AboutAccess;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AboutAccess: who may see the feedback about a page. The Forms
 * roles see every page's; anyone else only what the site's rule allows.
 */
final class AboutAccessTest extends TestCase
{
    private static App $kirby;

    private static App $withoutRule;

    public static function setUpBeforeClass(): void
    {
        $fixture = sys_get_temp_dir() . '/kirby-base-about-access-' . uniqid();
        foreach (['events/1_mine/event.txt' => 'Title: Mine', 'events/2_theirs/event.txt' => 'Title: Theirs'] as $path => $text) {
            mkdir(dirname($fixture . '/' . $path), 0777, true);
            file_put_contents($fixture . '/' . $path, $text);
        }
        file_put_contents($fixture . '/site.txt', 'Title: Test');
        $people = [
            'roles' => [['name' => 'admin'], ['name' => 'editor'], ['name' => 'local'], ['name' => 'member']],
            'users' => [
                ['id' => 'ed', 'email' => 'ed@example.org', 'role' => 'editor'],
                ['id' => 'lo', 'email' => 'lo@example.org', 'role' => 'local'],
                ['id' => 'me', 'email' => 'me@example.org', 'role' => 'member'],
            ],
        ];
        self::$withoutRule = KirbyTestEnvironment::bootWithContent($fixture, 'kirby-base-about-access-no-rule', $people);
        self::$kirby = KirbyTestEnvironment::bootWithContent($fixture, 'kirby-base-about-access', $people + [
            'options' => [
                'forms.libraryRoles' => ['admin', 'editor'],
                // The site's rule: local users see feedback about "Mine" only.
                'forms.aboutViewer' => static fn(User $user, Page $subject): bool
                    => $user->role()->id() === 'local' && $subject->title()->value() === 'Mine',
            ],
        ]);
    }

    public function testTheFormsRolesSeeEveryPagesFeedback(): void
    {
        $access = new AboutAccess(self::$kirby);
        $this->assertTrue($access->canSee($this->user('ed'), $this->page('events/mine')));
        $this->assertTrue($access->canSee($this->user('ed'), $this->page('events/theirs')));
    }

    public function testOthersSeeOnlyWhatTheSitesRuleAllows(): void
    {
        $access = new AboutAccess(self::$kirby);
        $this->assertTrue($access->canSee($this->user('lo'), $this->page('events/mine')));
        $this->assertFalse($access->canSee($this->user('lo'), $this->page('events/theirs')));
        $this->assertFalse($access->canSee($this->user('me'), $this->page('events/mine')));
        $this->assertFalse($access->canSee(null, $this->page('events/mine')));
    }

    public function testWithoutARuleOnlyTheFormsRolesSeeIt(): void
    {
        $access = new AboutAccess(self::$withoutRule);
        $local = self::$withoutRule->user('lo');
        $mine = self::$withoutRule->site()->find('events/mine');
        $this->assertInstanceOf(User::class, $local);
        $this->assertInstanceOf(Page::class, $mine);
        $this->assertFalse($access->canSee($local, $mine));
        $this->assertTrue($access->isFormsRole(self::$withoutRule->user('ed')));
    }

    private function user(string $id): User
    {
        $user = self::$kirby->user($id);
        $this->assertInstanceOf(User::class, $user, $id);
        return $user;
    }

    private function page(string $id): Page
    {
        $page = self::$kirby->site()->find($id);
        $this->assertInstanceOf(Page::class, $page, $id);
        return $page;
    }
}
