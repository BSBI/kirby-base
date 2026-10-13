<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\about;

use BSBI\WebBase\forms\about\AboutKinds;
use BSBI\WebBase\forms\about\AboutKindSaver;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AboutKindSaver: picking a page for an about link saves its kind in
 * the form's "Feedback is about" ticks, so the link works without the editor
 * remembering to save.
 */
final class AboutKindSaverTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        $fixture = sys_get_temp_dir() . '/kirby-base-about-kind-saver-' . uniqid();
        $files = [
            'none/form_builder.txt'      => "Title: No kinds\n\n----\n\nIntro: Keep me",
            'project/form_builder.txt'   => "Title: Projects only\n\n----\n\nAboutkinds: project, retired",
            'event/form_builder.txt'     => "Title: Events\n\n----\n\nAboutkinds: event",
            'refused/form_builder.txt'   => "Title: Not allowed",
            'pending/form_builder.txt'   => "Title: Pending\n\n----\n\nIntro: Saved intro",
            'other/default.txt'          => "Title: Not a form",
        ];
        foreach ($files as $path => $text) {
            mkdir(dirname($fixture . '/' . $path), 0777, true);
            file_put_contents($fixture . '/' . $path, $text);
        }
        file_put_contents($fixture . '/site.txt', 'Title: Test');
        self::$kirby = KirbyTestEnvironment::bootWithContent($fixture, 'kirby-base-about-kind-saver', [
            'blueprints' => [
                'pages/form_builder' => ['fields' => [
                    'intro'      => ['type' => 'text'],
                    'aboutKinds' => ['type' => 'checkboxes', 'options' => ['event' => 'Event', 'project' => 'Project']],
                ]],
            ],
            'options' => ['forms.aboutKinds' => [
                'event'   => ['label' => 'Event', 'templates' => ['event']],
                'project' => ['label' => 'Project', 'templates' => ['project']],
            ]],
        ]);
    }

    protected function setUp(): void
    {
        self::$kirby->impersonate('kirby');
    }

    public function testAKindAlreadySavedIsLeftAlone(): void
    {
        $this->assertSame(AboutKindSaver::ALREADY, $this->saver()->accept($this->page('event'), 'event'));
    }

    public function testAnUnsavedKindIsSavedAndTheRestOfThePageKept(): void
    {
        $this->assertSame(AboutKindSaver::SAVED, $this->saver()->accept($this->page('none'), 'event'));

        $form = $this->page('none');
        $this->assertSame(['event'], (new AboutKinds(self::$kirby))->forForm($form));
        $this->assertSame('Keep me', $form->content()->get('intro')->value());
    }

    public function testTheEditorsUnsavedChangesStayUnsaved(): void
    {
        $form = $this->page('pending');
        $form->version('changes')->save(['title' => 'Pending', 'intro' => 'Unsaved edit', 'aboutkinds' => 'event']);

        $this->assertSame(AboutKindSaver::SAVED, $this->saver()->accept($form, 'event'));

        $form = $this->page('pending');
        $this->assertSame('Saved intro', $form->version('latest')->content()->get('intro')->value());
        $this->assertSame('event', $form->version('latest')->content()->get('aboutkinds')->value());
        $this->assertSame('Unsaved edit', $form->version('changes')->content()->get('intro')->value());
    }

    public function testOtherSavedTicksAreKept(): void
    {
        $this->assertSame(AboutKindSaver::SAVED, $this->saver()->accept($this->page('project'), 'event'));

        $this->assertSame(['project', 'event'], (new AboutKinds(self::$kirby))->forForm($this->page('project')));
    }

    public function testAnUnknownKindIsRefused(): void
    {
        $this->assertSame(AboutKindSaver::REFUSED, $this->saver()->accept($this->page('refused'), 'meeting'));
        $this->assertTrue($this->page('refused')->content()->get('aboutkinds')->isEmpty());
    }

    public function testAPageThatIsNotAFormIsRefused(): void
    {
        $this->assertSame(AboutKindSaver::REFUSED, $this->saver()->accept($this->page('other'), 'event'));
        $this->assertTrue($this->page('other')->content()->get('aboutkinds')->isEmpty());
    }

    public function testAUserWhoCannotUpdateThePageIsRefused(): void
    {
        self::$kirby->impersonate(null);

        $this->assertSame(AboutKindSaver::REFUSED, $this->saver()->accept($this->page('refused'), 'event'));
        $this->assertTrue($this->page('refused')->content()->get('aboutkinds')->isEmpty());
    }

    private function saver(): AboutKindSaver
    {
        return new AboutKindSaver(self::$kirby, new AboutKinds(self::$kirby));
    }

    private function page(string $id): Page
    {
        $page = self::$kirby->page($id);
        $this->assertInstanceOf(Page::class, $page);
        return $page;
    }
}
