<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\about;

/**
 * The page a response is about, as read from a verified about link: its
 * `page://` UUID, a snapshot of its title and date (Y-m-d, or blank), and
 * the signed value to post back with the form.
 */
final readonly class AboutSubject
{
    /** The response fields that record the subject. */
    public const FIELDS = ['about', 'about_title', 'about_date'];

    public function __construct(
        public string $uuid,
        public string $title,
        public string $date,
        public string $signedValue,
    ) {
    }

    /**
     * Returns the subject's fields to store on a response.
     *
     * @return array{about: string, about_title: string, about_date: string}
     */
    public function fields(): array
    {
        return ['about' => $this->uuid, 'about_title' => $this->title, 'about_date' => $this->date];
    }

    /**
     * Returns the posted data with each "fill from the link" question answered
     * from the subject, replacing anything posted for it.
     *
     * @param array<mixed>          $postData
     * @param array<string, string> $fillFrom Key => 'title' or 'date' (fillFromKeys())
     * @return array<mixed>
     */
    public function fillAnswers(array $postData, array $fillFrom): array
    {
        foreach ($fillFrom as $key => $what) {
            $postData[$key] = $what === 'date' ? $this->date : $this->title;
        }
        return $postData;
    }
}
