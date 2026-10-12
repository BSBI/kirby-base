<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\about;

/**
 * The page a response is about, as read from a verified about link: its
 * `page://` UUID, a snapshot of its title, date and end date (Y-m-d, or
 * blank), its kind's label and other details, for the box naming it and
 * "fill from the link" questions, and the signed value to post back.
 */
final readonly class AboutSubject
{
    /** The response fields that record the subject. */
    public const FIELDS = ['about', 'about_title', 'about_date'];

    /**
     * @param array<string, array{label: string, value: string}> $details Field => label and value (non-blank)
     */
    public function __construct(
        public string $uuid,
        public string $title,
        public string $date,
        public string $signedValue,
        public string $kind = '',
        public string $endDate = '',
        public array $details = [],
    ) {
    }

    /**
     * Returns the answer the subject gives a "fill from the link" question:
     * its title, its date, or one of its details; blank when it has none.
     */
    public function fill(string $fillFrom): string
    {
        return match ($fillFrom) {
            'title' => $this->title,
            'date'  => $this->date,
            default => $this->details[$fillFrom]['value'] ?? '',
        };
    }

    /**
     * Returns the "fill from the link" questions the subject answers (those
     * it has a value for); the rest are asked as usual.
     *
     * @param array<string, string> $fillFrom Key => what fills it (fillFromKeys())
     * @return array<string, string>
     */
    public function answered(array $fillFrom): array
    {
        return array_filter($fillFrom, fn(string $what): bool => $this->fill($what) !== '');
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
     * Returns the posted data with each "fill from the link" question the
     * subject answers filled from it, replacing anything posted for it.
     *
     * @param array<mixed>          $postData
     * @param array<string, string> $fillFrom Key => what fills it (fillFromKeys())
     * @return array<mixed>
     */
    public function fillAnswers(array $postData, array $fillFrom): array
    {
        foreach ($this->answered($fillFrom) as $key => $what) {
            $postData[$key] = $this->fill($what);
        }
        return $postData;
    }
}
