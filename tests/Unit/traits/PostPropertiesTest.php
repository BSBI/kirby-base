<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\traits;

use BSBI\WebBase\models\User;
use BSBI\WebBase\models\UserList;
use BSBI\WebBase\traits\PostProperties;
use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Tests the PostProperties trait via a minimal anonymous concrete class.
 *
 * Covers subtitle, excerpt, postedBy (including 'Unknown' edge case),
 * publication date, and formatted date output.
 */
final class PostPropertiesTest extends TestCase
{
    /**
     * Create an anonymous class that uses PostProperties for testing.
     *
     * @return object
     */
    private function createModel(): object
    {
        return new class {
            use PostProperties;
        };
    }

    /**
     * Verify subtitle defaults to unset and can be set.
     */
    public function testSubtitleGetterSetter(): void
    {
        $model = $this->createModel();

        $this->assertFalse($model->hasSubtitle());

        $model->setSubtitle('A Subtitle');
        $this->assertTrue($model->hasSubtitle());
        $this->assertSame('A Subtitle', $model->getSubtitle());
    }

    /**
     * Verify excerpt defaults to an empty string.
     */
    public function testExcerptDefaultsToEmpty(): void
    {
        $model = $this->createModel();

        $this->assertSame('', $model->getExcerpt());
    }

    /**
     * Verify excerpt can be set and retrieved.
     */
    public function testExcerptGetterSetter(): void
    {
        $model = $this->createModel();
        $model->setExcerpt('A brief summary');

        $this->assertSame('A brief summary', $model->getExcerpt());
    }

    /**
     * Verify postedBy defaults to unset and can be set.
     */
    public function testPostedByGetterSetter(): void
    {
        $model = $this->createModel();

        $this->assertFalse($model->hasPostedBy());

        $model->setPostedBy('Jane Doe');
        $this->assertTrue($model->hasPostedBy());
        $this->assertSame('Jane Doe', $model->getPostedBy());
    }

    /**
     * Verify that 'Unknown' is treated as not having a postedBy value.
     */
    public function testPostedByUnknownTreatedAsNotSet(): void
    {
        $model = $this->createModel();
        $model->setPostedBy('Unknown');

        $this->assertFalse($model->hasPostedBy());
    }

    /**
     * Verify posters defaults to unset and can be set as a UserList.
     */
    public function testPostersGetterSetter(): void
    {
        $model = $this->createModel();

        $this->assertFalse($model->hasPosters());

        $posters = new UserList();
        $posters->addListItem((new User('Jane Doe'))->setUserName('Jane Doe'));
        $model->setPosters($posters);

        $this->assertTrue($model->hasPosters());
        $this->assertSame($posters, $model->getPosters());
        $this->assertSame(1, $model->getPosters()->count());
    }

    /**
     * Verify an empty posters list is treated as not having posters.
     */
    public function testEmptyPostersTreatedAsNotSet(): void
    {
        $model = $this->createModel();
        $model->setPosters(new UserList());

        $this->assertFalse($model->hasPosters());
    }

    /**
     * Verify publication date can be set and retrieved as a DateTime instance.
     */
    public function testPublicationDateGetterSetter(): void
    {
        $model = $this->createModel();

        $this->assertFalse($model->hasPublicationDate());

        $date = new DateTime('2024-03-15');
        $model->setPublicationDate($date);

        $this->assertTrue($model->hasPublicationDate());
        $this->assertSame($date, $model->getPublicationDate());
    }

    /**
     * Verify getFormattedPublicationDate() returns the date in 'j F Y' format.
     */
    public function testFormattedPublicationDate(): void
    {
        $model = $this->createModel();
        $model->setPublicationDate(new DateTime('2024-03-15'));

        $this->assertSame('15 March 2024', $model->getFormattedPublicationDate());
    }

    /**
     * Verify getFormattedPublicationDate() returns an empty string (rather than
     * fatally accessing an uninitialised typed property) when no publication date
     * has been set — e.g. a content-index row with an empty publication_date.
     */
    public function testFormattedPublicationDateEmptyWhenNotSet(): void
    {
        $model = $this->createModel();

        $this->assertFalse($model->hasPublicationDate());
        $this->assertSame('', $model->getFormattedPublicationDate());
    }
}
