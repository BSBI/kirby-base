<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use BSBI\WebBase\helpers\PanelShortcuts;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase;

/**
 * A Panel user's own shortcuts (kirby-base#75): stored on the user's content
 * without a blueprint field, written even when the role can't update itself,
 * and listed without ever scanning the site index.
 */
final class PanelShortcutsTest extends TestCase
{
    private const string EDITOR = 'editor@test.invalid';
    private const string OTHER = 'other@test.invalid';

    private static string $root;
    private App $kirby;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$root = sys_get_temp_dir() . '/kirby-base-shortcuts-' . uniqid();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Dir::remove(self::$root);
        self::makePage('1_alpha', 'default', 'Alpha', 'alpha0000000001');
        self::makePage('2_beta', 'default', 'Beta', 'beta00000000001');
        self::makePage('2_beta/1_child', 'default', 'Beta child', 'child0000000001');
        self::makePage('3_secret', 'shortcutsecret', 'Secret', 'secret000000001');
        mkdir(self::$root . '/accounts', 0777, true);

        $this->kirby = $this->boot();
        $this->kirby->impersonate('kirby', function (): void {
            $this->kirby->users()->create(['email' => self::EDITOR, 'role' => 'editor', 'password' => 'long-password-1']);
            $this->kirby->users()->create(['email' => self::OTHER, 'role' => 'editor', 'password' => 'long-password-1']);
        });
        $this->kirby = $this->boot();
        $this->kirby->impersonate(self::EDITOR);
    }

    public static function tearDownAfterClass(): void
    {
        Dir::remove(self::$root);
        parent::tearDownAfterClass();
    }

    public function testAnEmptyListToStartWith(): void
    {
        $this->assertSame([], $this->service()->list($this->editor()));
    }

    public function testAddedPagesAreListedInOrderWithTheirPanelLinks(): void
    {
        $this->service()->add($this->editor(), $this->page('beta'), 'events');
        $this->service()->add($this->editor(), $this->page('alpha'));

        $list = $this->service()->list($this->editor());

        $this->assertSame(['Beta', 'Alpha'], array_column($list, 'title'));
        $this->assertStringEndsWith('/pages/beta?tab=events', $list[0]['url']);
        $this->assertStringEndsWith('/pages/alpha', $list[1]['url']);
        $this->assertSame('page://beta00000000001', $list[0]['uuid']);
    }

    public function testTheListSurvivesAReloadAndALaterSaveOfOtherFields(): void
    {
        $this->service()->add($this->editor(), $this->page('alpha'));
        $this->kirby->impersonate('kirby', fn() => $this->editor()->update(['name' => 'Ed']));

        $this->kirby = $this->boot();
        $this->kirby->impersonate(self::EDITOR);

        $this->assertSame(['Alpha'], array_column($this->service()->list($this->editor()), 'title'));
    }

    public function testAddingAgainKeepsOneEntryAndTakesTheNewTab(): void
    {
        $this->service()->add($this->editor(), $this->page('alpha'), 'one');
        $this->service()->add($this->editor(), $this->page('alpha'), 'two');

        $list = $this->service()->list($this->editor());
        $this->assertCount(1, $list);
        $this->assertStringEndsWith('?tab=two', $list[0]['url']);
    }

    public function testAnUnsafeTabIsDropped(): void
    {
        $this->service()->add($this->editor(), $this->page('alpha'), 'x" onmouseover="y');

        $this->assertStringEndsWith('/pages/alpha', $this->service()->list($this->editor())[0]['url']);
    }

    public function testHasReportsWhetherThePageIsSaved(): void
    {
        $this->assertFalse($this->service()->has($this->editor(), $this->page('alpha')));
        $this->service()->add($this->editor(), $this->page('alpha'));
        $this->assertTrue($this->service()->has($this->editor(), $this->page('alpha')));
    }

    public function testRemove(): void
    {
        $this->service()->add($this->editor(), $this->page('alpha'));
        $this->service()->add($this->editor(), $this->page('beta'));

        $this->service()->remove($this->editor(), 'page://alpha0000000001');

        $this->assertSame(['Beta'], array_column($this->service()->list($this->editor()), 'title'));
    }

    public function testMoveAndStep(): void
    {
        foreach (['alpha', 'beta', 'beta/child'] as $id) {
            $this->service()->add($this->editor(), $this->page($id));
        }

        $this->service()->move($this->editor(), 'page://child0000000001', 0);
        $this->assertSame(['Beta child', 'Alpha', 'Beta'], $this->titles());

        $this->service()->step($this->editor(), 'page://child0000000001', 1);
        $this->assertSame(['Alpha', 'Beta child', 'Beta'], $this->titles());

        // Past either end: nothing changes.
        $this->service()->step($this->editor(), 'page://alpha0000000001', -1);
        $this->service()->move($this->editor(), 'page://beta00000000001', 99);
        $this->assertSame(['Alpha', 'Beta child', 'Beta'], $this->titles());
    }

    public function testAStepSkipsOverAShortcutTheUserCannotSee(): void
    {
        $this->service()->add($this->editor(), $this->page('alpha'));
        $this->kirby->impersonate('kirby', fn() => $this->service()->add($this->editor(), $this->page('secret')));
        $this->service()->add($this->editor(), $this->page('beta'));

        $this->service()->step($this->editor(), 'page://beta00000000001', -1);

        $this->assertSame(['Beta', 'Alpha'], $this->titles());
    }

    public function testAPageTheUserMayNotAccessIsLeftOut(): void
    {
        $this->kirby->impersonate('kirby', fn() => $this->service()->add($this->editor(), $this->page('secret')));
        $this->kirby->impersonate(self::EDITOR);

        $this->assertSame([], $this->service()->list($this->editor()));
        $this->assertCount(1, $this->service()->stored($this->editor()));
    }

    public function testPagesInPlaceAreFoundWithoutAnIndexLookup(): void
    {
        $this->service()->add($this->editor(), $this->page('alpha'));
        $this->service()->add($this->editor(), $this->page('beta/child'));

        // With index lookups switched off, an index lookup would fail.
        $this->kirby = $this->boot(['content' => ['uuid' => ['index' => false]]]);
        $this->kirby->impersonate(self::EDITOR);

        $this->assertSame(['Alpha', 'Beta child'], $this->titles());
    }

    public function testADeletedPageIsLeftOutAndDropped(): void
    {
        $this->service()->add($this->editor(), $this->page('alpha'));
        $this->service()->add($this->editor(), $this->page('beta'));
        Dir::remove(self::$root . '/content/1_alpha');
        $this->kirby = $this->boot();
        $this->kirby->impersonate(self::EDITOR);

        $this->assertSame(['Beta'], $this->titles());
        $this->assertSame(['page://beta00000000001'], array_column($this->service()->stored($this->editor()), 'uuid'));
    }

    public function testAMovedPageIsFoundAndItsNewPathStored(): void
    {
        $this->service()->add($this->editor(), $this->page('beta/child'), 'events');
        $this->kirby->impersonate('kirby', fn() => $this->page('beta/child')->changeSlug('kid'));
        $this->kirby = $this->boot();
        $this->kirby->impersonate(self::EDITOR);

        $list = $this->service()->list($this->editor());
        $this->assertSame(['Beta child'], array_column($list, 'title'));
        $this->assertStringEndsWith('/pages/beta+kid?tab=events', $list[0]['url']);
        $this->assertSame('beta/kid', $this->service()->stored($this->editor())[0]['id']);

        // From now on, found by path again.
        $this->kirby = $this->boot(['content' => ['uuid' => ['index' => false]]]);
        $this->kirby->impersonate(self::EDITOR);
        $this->assertSame(['Beta child'], $this->titles());
    }

    public function testOneUserObjectSeesItsOwnChanges(): void
    {
        // As in an API route: the user is fetched once, then changed and read.
        $user = $this->editor();
        $service = $this->service();
        $service->add($user, $this->page('alpha'));
        $service->add($user, $this->page('beta'));
        $service->step($user, 'page://alpha0000000001', 1);

        $this->assertSame(['Beta', 'Alpha'], array_column($service->list($user), 'title'));
        $service->remove($user, 'page://beta00000000001');
        $this->assertSame(['Alpha'], array_column($service->list($user), 'title'));
    }

    public function testEachUsersListIsTheirOwn(): void
    {
        $this->service()->add($this->editor(), $this->page('alpha'));

        $other = $this->kirby->user(self::OTHER);
        $this->assertInstanceOf(User::class, $other);
        $this->assertSame([], $this->service()->stored($other));
    }

    public function testEveryRoleHasShortcutsUnlessTheOptionNamesSome(): void
    {
        $this->assertTrue(PanelShortcuts::isAllowed($this->kirby, $this->editor()));
        $this->assertIsArray(PanelShortcuts::viewButton($this->page('alpha')));

        $this->kirby = $this->boot(['panelShortcuts' => ['roles' => ['admin']]]);
        $this->kirby->impersonate(self::EDITOR);

        $this->assertFalse(PanelShortcuts::isAllowed($this->kirby, $this->editor()));
        $this->assertNull(PanelShortcuts::viewButton($this->page('alpha')));
        $this->expectException(\Kirby\Exception\PermissionException::class);
        PanelShortcuts::currentUser($this->kirby);
    }

    public function testTheViewButtonKnowsWhetherThePageIsSaved(): void
    {
        $button = PanelShortcuts::viewButton($this->page('alpha'));
        $this->assertNotNull($button);
        $this->assertFalse($button['saved']);
        $this->assertSame('alpha', $button['pageId']);
        $this->assertSame('Alpha', $button['pageTitle']);

        $this->service()->add($this->editor(), $this->page('alpha'));
        $this->assertTrue(PanelShortcuts::viewButton($this->page('alpha'))['saved'] ?? null);
    }

    public function testTheListIsCapped(): void
    {
        $items = [];
        for ($i = 0; $i < PanelShortcuts::MAX; $i++) {
            $items[] = ['uuid' => 'page://filler' . $i, 'id' => 'filler' . $i, 'tab' => ''];
        }
        $this->kirby->impersonate('kirby', fn() => $this->editor()->update([
            PanelShortcuts::FIELD => \Kirby\Data\Yaml::encode($items),
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->service()->add($this->editor(), $this->page('alpha'));
    }

    public function testGarbageInTheFieldIsIgnored(): void
    {
        $this->kirby->impersonate('kirby', fn() => $this->editor()->update([
            PanelShortcuts::FIELD => "- uuid: 'javascript:alert(1)'\n- just a string\n- uuid: page://alpha0000000001\n  id: alpha\n",
        ]));

        $this->assertSame(['Alpha'], $this->titles());
    }

    /**
     * @return list<string>
     */
    private function titles(): array
    {
        return array_column($this->service()->list($this->editor()), 'title');
    }

    private function service(): PanelShortcuts
    {
        return new PanelShortcuts($this->kirby);
    }

    private function editor(): User
    {
        $user = $this->kirby->user(self::EDITOR);
        $this->assertInstanceOf(User::class, $user);
        return $user;
    }

    private function page(string $id): Page
    {
        $page = $this->kirby->page($id);
        $this->assertInstanceOf(Page::class, $page);
        return $page;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function boot(array $options = []): App
    {
        App::$enableWhoops = false;
        return new App([
            'roots' => [
                'index'    => self::$root,
                'content'  => self::$root . '/content',
                'accounts' => self::$root . '/accounts',
                'cache'    => self::$root . '/cache',
                'logs'     => self::$root . '/logs',
            ],
            'options' => $options,
            'blueprints' => [
                // Like bsbi-web's editors: no account view, may not update themselves.
                'users/editor' => [
                    'name' => 'editor',
                    'title' => 'Editor',
                    'permissions' => [
                        'access' => ['account' => false, 'panel' => true],
                        'user' => ['update' => false],
                    ],
                ],
                'pages/default' => ['title' => 'Default'],
                'pages/shortcutsecret' => ['title' => 'Secret', 'options' => ['access' => ['editor' => false]]],
            ],
        ]);
    }

    private static function makePage(string $dir, string $template, string $title, string $uuid): void
    {
        mkdir(self::$root . '/content/' . $dir, 0777, true);
        file_put_contents(
            self::$root . '/content/' . $dir . '/' . $template . '.txt',
            "Title: $title\n\n----\n\nUuid: $uuid\n"
        );
    }
}
