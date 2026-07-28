<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

/**
 * The certificates awarded to one person.
 *
 * Reading and writing go through here so the stored shape stays in one place:
 * a student dashboard asking "is there a certificate for this course" and a
 * batch run asking "has this person already been issued one" are the same
 * question, and answering it differently in two places is how duplicates happen.
 *
 * @package BSBI\WebBase
 */
final readonly class CertificateIssueList
{
    /** @var CertificateIssue[] The records held, in the order supplied */
    private array $issues;

    /**
     * @param CertificateIssue[] $issues The records to hold
     */
    public function __construct(array $issues = [])
    {
        $this->issues = array_values(array_filter(
            $issues,
            static fn(CertificateIssue $issue): bool => $issue->isValid()
        ));
    }

    /**
     * Build a list from stored field data.
     *
     * @param array<int, array<string, mixed>> $rows The stored rows
     * @return self The list
     */
    public static function fromArray(array $rows): self
    {
        return new self(array_map(
            static fn(array $row): CertificateIssue => CertificateIssue::fromArray($row),
            array_values($rows)
        ));
    }

    /**
     * The list in the shape it is stored in.
     *
     * @return array<int, array<string, string>> The stored rows
     */
    public function toArray(): array
    {
        return array_map(
            static fn(CertificateIssue $issue): array => $issue->toArray(),
            $this->issues
        );
    }

    /**
     * The records held.
     *
     * @return CertificateIssue[] The records
     */
    public function all(): array
    {
        return $this->issues;
    }

    /**
     * How many records the list holds.
     *
     * @return int The number of records
     */
    public function count(): int
    {
        return count($this->issues);
    }

    /**
     * Whether a certificate has been awarded for the given context.
     *
     * @param string $contextId The context id to look for
     * @return bool True when a record exists for that context
     */
    public function hasForContext(string $contextId): bool
    {
        return $this->findForContext($contextId) instanceof CertificateIssue;
    }

    /**
     * The record for the given context, if there is one.
     *
     * @param string $contextId The context id to look for
     * @return CertificateIssue|null The record, or null when none exists
     */
    public function findForContext(string $contextId): ?CertificateIssue
    {
        foreach ($this->issues as $issue) {
            if ($issue->isForContext($contextId)) {
                return $issue;
            }
        }

        return null;
    }

    /**
     * This list with the given record added, replacing any record for the same
     * context.
     *
     * Replacing rather than appending keeps reissues idempotent: a batch run that
     * is retried after a failure must not leave a student holding two records for
     * one course.
     *
     * @param CertificateIssue $issue The record to add
     * @return self A new list including the record
     */
    public function with(CertificateIssue $issue): self
    {
        if (!$issue->isValid()) {
            return $this;
        }

        $kept = array_filter(
            $this->issues,
            static fn(CertificateIssue $existing): bool => !$existing->isForContext($issue->getContextId())
        );

        return new self([...array_values($kept), $issue]);
    }
}
