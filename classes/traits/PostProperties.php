<?php

namespace BSBI\WebBase\traits;

use BSBI\WebBase\models\UserList;
use DateTime;

/**
 *
 */
trait PostProperties
{
    /** @var DateTime */
    private DateTime $publicationDate;

    private string $subtitle;

    /**
     * @var string
     */
    private string $excerpt;

    private string $postedBy;

    private UserList $posters;

    public function hasSubtitle(): bool
    {
        return !empty($this->subtitle);
    }

    /**
     * @return string
     */
    public function getSubtitle(): string
    {
        return $this->subtitle;
    }

    /**
     * @param string $subtitle
     * @return $this
     */
    public function setSubtitle(string $subtitle): static
    {
        $this->subtitle = $subtitle;
        return $this;
    }

    /**
     * @return string
     */
    public function getExcerpt(): string
    {
        return $this->excerpt ?? '';
    }

    /**
     * @param string $excerpt
     * @return $this
     */
    public function setExcerpt(string $excerpt): static
    {
        $this->excerpt = $excerpt;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasPostedBy(): bool
    {
        return !empty($this->postedBy) && ($this->postedBy !== 'Unknown');
    }

    /**
     * @return string
     */
    public function getPostedBy(): string
    {
        return $this->postedBy;
    }

    /**
     * @param string $postedBy
     * @return $this
     */
    public function setPostedBy(string $postedBy): static
    {
        $this->postedBy = $postedBy;
        return $this;
    }

    /**
     * Whether one or more poster (author) details are available.
     *
     * @return bool
     */
    public function hasPosters(): bool
    {
        return isset($this->posters) && $this->posters->hasListItems();
    }

    /**
     * Get the list of posters (authors) with their full details.
     *
     * @return UserList
     */
    public function getPosters(): UserList
    {
        return $this->posters;
    }

    /**
     * Set the list of posters (authors) with their full details.
     *
     * @param UserList $posters
     * @return $this
     */
    public function setPosters(UserList $posters): static
    {
        $this->posters = $posters;
        return $this;
    }

    public function hasPublicationDate(): bool {
        return isset($this->publicationDate);
    }

    /**
     * @param DateTime $publicationDate
     * @return $this
     */
    public function setPublicationDate(DateTime $publicationDate): static
    {
        $this->publicationDate = $publicationDate;
        return $this;
    }

    /**
     * @return DateTime
     */
    public function getPublicationDate(): DateTime
    {
        return $this->publicationDate;
    }

    /**
     * Get the date in 'j F Y' format, or an empty string when no publication
     * date has been set (e.g. a content-index row with an empty publication_date),
     * so callers can render the value directly without a fatal on the uninitialised
     * typed property.
     *
     * @return string
     */
    public function getFormattedPublicationDate(): string {
        if (!isset($this->publicationDate)) {
            return '';
        }
        return $this->publicationDate->format('j F Y');
    }

}