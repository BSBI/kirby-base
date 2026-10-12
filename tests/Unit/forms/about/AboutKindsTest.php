<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\about;

use BSBI\WebBase\forms\about\AboutKinds;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AboutKinds: the configured kinds, which a form accepts, and the
 * pages to choose from.
 */
final class AboutKindsTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        $fixture = sys_get_temp_dir() . '/kirby-base-about-kinds-' . uniqid();
        $files = [
            'form/form_builder.txt'           => "Title: Form\n\n----\n\nAboutkinds: event, project, unknown",
            'events/events.txt'               => "Title: Events",
            'events/1_walk/event.txt'         => "Title: Kenfig walk\n\n----\n\nStartdate: 2026-09-14",
            'events/2_talk/event.txt'         => "Title: Winter talk\n\n----\n\nStartdate: 2026-12-01",
            'events/3_old/event.txt'          => "Title: Kenfig walk\n\n----\n\nStartdate: 2024-09-15",
            'events/draft-only/event.txt'     => "Title: Unpublished",
            'events/4_news/default.txt'       => "Title: Not an event",
            'projects/projects.txt'           => "Title: Projects",
            'projects/1_survey/project.txt'   => "Title: Survey",
        ];
        foreach ($files as $path => $text) {
            if (!is_dir(dirname($fixture . '/' . $path))) {
                mkdir(dirname($fixture . '/' . $path), 0777, true);
            }
            file_put_contents($fixture . '/' . $path, $text);
        }
        file_put_contents($fixture . '/site.txt', 'Title: Test');
        self::$kirby = KirbyTestEnvironment::bootWithContent($fixture, 'kirby-base-about-kinds', [
            'options' => ['forms.aboutKinds' => [
                'event'   => ['label' => 'Event', 'templates' => ['event'], 'parent' => 'events', 'dateField' => 'startDate'],
                'project' => ['label' => 'Project', 'templates' => ['project'], 'parent' => 'projects'],
                'broken'  => ['label' => 'No templates'],
            ]],
        ]);
    }

    public function testKindsNeedTemplatesAndGetDefaults(): void
    {
        $kinds = (new AboutKinds(self::$kirby))->all();

        $this->assertSame(['event', 'project'], array_keys($kinds));
        $this->assertSame('title', $kinds['project']['titleColumn']);
    }

    public function testAFormAcceptsItsTickedKnownKinds(): void
    {
        $this->assertSame(['event', 'project'], (new AboutKinds(self::$kirby))->forForm($this->page('form')));
    }

    public function testAPagesKindAndDate(): void
    {
        $kinds = new AboutKinds(self::$kirby);

        $this->assertSame('event', $kinds->kindOf($this->page('events/walk')));
        $this->assertNull($kinds->kindOf($this->page('events/news')));
        $this->assertSame('2026-09-14', $kinds->dateOf($this->page('events/walk')));
        $this->assertSame('', $kinds->dateOf($this->page('projects/survey')));
    }

    public function testChoicesAreListedPagesOfTheKindsNewestFirstAndSearchable(): void
    {
        $kinds = new AboutKinds(self::$kirby);

        $all = $kinds->choices(['event', 'project']);
        $this->assertSame(
            ['Winter talk', 'Kenfig walk', 'Kenfig walk', 'Survey'],
            array_column($all, 'title'),
            'dated first, newest first; drafts and other templates left out'
        );
        $this->assertSame(['2026-09-14', '2024-09-15'], array_column($kinds->choices(['event'], 'kenfig'), 'date'));
        $this->assertStringStartsWith('page://', $all[0]['value']);
        $this->assertSame('Events', $all[0]['where']);
        $this->assertSame('Event', $all[0]['kind']);
        $this->assertCount(1, $kinds->choices(['event'], '', 1));
    }

    private function page(string $id): Page
    {
        $page = self::$kirby->site()->findPageOrDraft($id) ?? self::$kirby->site()->draft($id);
        $this->assertInstanceOf(Page::class, $page, $id);
        return $page;
    }
}
