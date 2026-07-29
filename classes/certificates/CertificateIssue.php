<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

/**
 * A record that a certificate was awarded to somebody.
 *
 * The record is the durable thing, not the PDF. Certificates are cheap to
 * regenerate and a stored file goes stale the moment a name is corrected, so
 * only the fact of the award is kept and the document is rendered on demand.
 *
 * "Context" is deliberately vague: a course for a taught certificate, a
 * challenge or a year for an award that has no course behind it at all. Keeping
 * it untyped is what lets one service serve both.
 *
 * @package BSBI\WebBase
 */
final readonly class CertificateIssue
{
    /**
     * @param string $recipientId The id of the user the certificate was awarded to
     * @param string $contextId The id of the thing it was awarded for, e.g. a course
     * @param string $issuedOn The date awarded, as 'YYYY-MM-DD'
     * @param string $templateName The template used, recorded so a reissue can be
     *                             recognised as coming from a different design
     * @param string $reference An unguessable identifier for this award, used in
     *                          links sent to the recipient. Replacing it revokes
     *                          every link previously issued for this award and
     *                          nobody else's.
     */
    public function __construct(
        private string $recipientId,
        private string $contextId,
        private string $issuedOn,
        private string $templateName = '',
        private string $reference = ''
    ) {
    }

    /**
     * Generate an unguessable reference for a new award.
     *
     * Cryptographically random rather than time-based: a reference that can be
     * predicted from when it was issued would let somebody derive a link they
     * were never sent, which is the whole property this is here to provide.
     *
     * @return string The reference, as 32 hex characters
     */
    public static function generateReference(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Build an issue record from stored field data.
     *
     * Unknown keys are ignored and missing ones become empty, so a record written
     * by an older version still loads rather than breaking a student's dashboard.
     *
     * @param array<string, mixed> $data The stored data
     * @return self The issue record
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::readString($data, 'recipient'),
            self::readString($data, 'context'),
            self::readString($data, 'issued'),
            self::readString($data, 'template'),
            self::readString($data, 'reference')
        );
    }

    /**
     * Read one stored value as a string.
     *
     * A value that is absent, or not a scalar at all, becomes an empty string
     * rather than an error: stored content is edited by hand often enough that a
     * malformed row should cost one dashboard link, not the whole page.
     *
     * @param array<string, mixed> $data The stored data
     * @param string $key The key to read
     * @return string The value, or an empty string
     */
    private static function readString(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * The record in the shape it is stored in.
     *
     * @return array<string, string> The stored data
     */
    public function toArray(): array
    {
        return [
            'recipient' => $this->recipientId,
            'context' => $this->contextId,
            'issued' => $this->issuedOn,
            'template' => $this->templateName,
            'reference' => $this->reference,
        ];
    }

    /**
     * The id of the user the certificate was awarded to.
     *
     * @return string The recipient id
     */
    public function getRecipientId(): string
    {
        return $this->recipientId;
    }

    /**
     * The id of the thing the certificate was awarded for.
     *
     * @return string The context id
     */
    public function getContextId(): string
    {
        return $this->contextId;
    }

    /**
     * The date the certificate was awarded, as stored.
     *
     * @return string The date as 'YYYY-MM-DD'
     */
    public function getIssuedOn(): string
    {
        return $this->issuedOn;
    }

    /**
     * The template the certificate was issued from.
     *
     * @return string The template name
     */
    public function getTemplateName(): string
    {
        return $this->templateName;
    }

    /**
     * The unguessable reference used in links to this award.
     *
     * @return string The reference
     */
    public function getReference(): string
    {
        return $this->reference;
    }

    /**
     * Whether this award can be reached by a link.
     *
     * An award recorded before references existed has none, so it is offered
     * through the dashboard but cannot be sent as a link until reissued.
     *
     * @return bool True when the award has a reference
     */
    public function isLinkable(): bool
    {
        return $this->reference !== '';
    }

    /**
     * This award with a freshly generated reference.
     *
     * Every link previously sent for this award stops working, which is what
     * makes reissuing a link a meaningful action rather than a duplicate one.
     *
     * @return self The award with a new reference
     */
    public function withNewReference(): self
    {
        return new self(
            $this->recipientId,
            $this->contextId,
            $this->issuedOn,
            $this->templateName,
            self::generateReference()
        );
    }

    /**
     * Whether this record refers to the given context.
     *
     * @param string $contextId The context id to compare against
     * @return bool True when the record is for that context
     */
    public function isForContext(string $contextId): bool
    {
        return $this->contextId !== '' && $this->contextId === $contextId;
    }

    /**
     * Whether the record carries enough detail to be meaningful.
     *
     * A record missing its recipient or context cannot be matched to anybody, so
     * it is treated as absent rather than shown as a certificate nobody can open.
     *
     * @return bool True when the record is usable
     */
    public function isValid(): bool
    {
        return $this->recipientId !== '' && $this->contextId !== '';
    }
}
