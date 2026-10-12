<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\about;

use BSBI\WebBase\forms\about\AboutKinds;
use BSBI\WebBase\forms\about\AboutLink;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AboutLink: signed links to a form that name what the feedback is
 * about, and reading them back.
 */
final class AboutLinkTest extends TestCase
{
    private static App $kirby;

    public static function setUpBeforeClass(): void
    {
        $fixture = sys_get_temp_dir() . '/kirby-base-about-link-' . uniqid();
        $files = [
            'feedback/form_builder.txt'      => "Title: Feedback\n\n----\n\nAboutkinds: event\n\n----\n\nUuid: feedbackform",
            'other-form/form_builder.txt'    => "Title: Other\n\n----\n\nAboutkinds: event\n\n----\n\nUuid: otherform",
            'events/1_walk/event.txt'        => "Title: Kenfig walk\n\n----\n\nStartdate: 2026-09-14\n\n----\n\nUuid: kenfigwalk",
            'projects/1_survey/project.txt'  => "Title: Survey\n\n----\n\nUuid: surveyproj",
        ];
        foreach ($files as $path => $text) {
            if (!is_dir(dirname($fixture . '/' . $path))) {
                mkdir(dirname($fixture . '/' . $path), 0777, true);
            }
            file_put_contents($fixture . '/' . $path, $text);
        }
        file_put_contents($fixture . '/site.txt', 'Title: Test');
        self::$kirby = KirbyTestEnvironment::bootWithContent($fixture, 'kirby-base-about-link', [
            'options' => [
                'forms.aboutSecret' => 'test-secret',
                'forms.aboutKinds'  => [
                    'event'   => ['label' => 'Event', 'templates' => ['event'], 'parent' => 'events', 'dateField' => 'startDate'],
                    'project' => ['label' => 'Project', 'templates' => ['project'], 'parent' => 'projects'],
                ],
            ],
        ]);
    }

    public function testALinkNamesTheSubjectAndReadsBack(): void
    {
        $link = $this->links()->link($this->page('feedback'), $this->page('events/walk'));

        $this->assertStringStartsWith($this->page('feedback')->url() . '?about=kenfigwalk.', $link);
        $param = (string) parse_url($link, PHP_URL_QUERY);
        parse_str($param, $query);
        $subject = $this->links()->read($this->page('feedback'), (string) $query['about']);

        $this->assertInstanceOf(Page::class, $subject);
        $this->assertSame('events/walk', $subject->id());
    }

    public function testATamperedOrMovedLinkNamesNoSubject(): void
    {
        $form = $this->page('feedback');
        $value = $this->links()->value($form, $this->page('events/walk'));
        [$id, $sig] = explode('.', $value);

        $this->assertNull($this->links()->read($form, $id . '.' . strrev($sig)), 'edited signature');
        $this->assertNull($this->links()->read($form, 'surveyproj.' . $sig), 'other subject, old signature');
        $this->assertNull($this->links()->read($this->page('other-form'), $value), 'another form\'s link');
        $this->assertNull($this->links()->read($form, $id), 'no signature');
        $this->assertNull($this->links()->read($form, ''), 'nothing');
        $this->assertNull((new AboutLink(self::$kirby, new AboutKinds(self::$kirby), 'other-secret'))->read($form, $value), 'rotated secret');
    }

    public function testASubjectOfAKindTheFormDoesNotAcceptNamesNoSubject(): void
    {
        $form = $this->page('feedback');
        $value = $this->links()->value($form, $this->page('projects/survey'));

        $this->assertNull($this->links()->read($form, $value));
    }

    private function links(): AboutLink
    {
        return new AboutLink(self::$kirby, new AboutKinds(self::$kirby));
    }

    private function page(string $id): Page
    {
        $page = self::$kirby->site()->findPageOrDraft($id) ?? self::$kirby->site()->draft($id);
        $this->assertInstanceOf(Page::class, $page, $id);
        return $page;
    }
}
