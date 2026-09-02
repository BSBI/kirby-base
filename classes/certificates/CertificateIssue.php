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
     * @param string $emailedOn The date the recipient was last successfully sent
     *                          their link, as 'YYYY-MM-DD', or '' if never. This
     *                          is the only thing that can answer "who still needs
     *                          telling?" — the award itself says nothing about
     *                          whether anybody heard about it.
     * @param string $certificateId The printable, permanent identity of this
     *                              award, or '' for awards made before ids
     *                              existed. The deliberate opposite of the
     *                              reference: public rather than secret, and
     *                              never replaced, so a printed certificate goes
     *                              on matching the stored record for ever.
     */
    public function __construct(
        private string $recipientId,
        private string $contextId,
        private string $issuedOn,
        private string $templateName = '',
        private string $reference = '',
        private string $emailedOn = '',
        private string $certificateId = ''
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
     * Generate a certificate id for a new award.
     *
     * A GUID rather than anything sequential, so ids can be minted anywhere —
     * a batch run, a single award, another consuming site — without a counter
     * to coordinate or to leak how many certificates have been issued.
     *
     * @return string The id, as a lowercase v4-format GUID
     */
    public static function generateCertificateId(): string
    {
        $bytes = random_bytes(16);
        // Stamp the version and variant bits so the result reads as a
        // well-formed v4 GUID to anything that validates one.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
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
            self::readString($data, 'reference'),
            self::readString($data, 'emailed'),
            self::readString($data, 'certificateid')
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
            'emailed' => $this->emailedOn,
            'certificateid' => $this->certificateId,
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
     * The printable, permanent identity of this award.
     *
     * @return string The certificate id, or '' for awards made before ids existed
     */
    public function getCertificateId(): string
    {
        return $this->certificateId;
    }

    /**
     * The date the recipient was last successfully sent their link.
     *
     * @return string The date as 'YYYY-MM-DD', or '' if never
     */
    public function getEmailedOn(): string
    {
        return $this->emailedOn;
    }

    /**
     * Whether the recipient has been sent a link that still works.
     *
     * @return bool True when they have been emailed since the current reference
     *              was issued
     */
    public function hasBeenEmailed(): bool
    {
        return $this->emailedOn !== '';
    }

    /**
     * This award, recorded as emailed on the given date.
     *
     * @param string $emailedOn The date sent, as 'YYYY-MM-DD'
     * @return self The award with the send recorded
     */
    public function withEmailedOn(string $emailedOn): self
    {
        return new self(
            $this->recipientId,
            $this->contextId,
            $this->issuedOn,
            $this->templateName,
            $this->reference,
            $emailedOn,
            $this->certificateId
        );
    }

    /**
     * This award, granted again with a new date and design.
     *
     * Awarding again for the same context is how a date gets corrected, so it
     * keeps the existing reference and every link already sent goes on working.
     * The emailed date travels with the reference for the same reason: forgetting
     * it while keeping the link alive would report the recipient as never told
     * when they are holding something that still works, and a later "send to
     * everyone not yet emailed" would write to them a second time.
     *
     * The counterpart of withNewReference(), which does the opposite in both
     * respects because it deliberately breaks the old link.
     *
     * @param string $issuedOn The new award date, as 'YYYY-MM-DD'
     * @param string $templateName The design used this time
     * @return self The award, re-granted
     */
    public function reawarded(string $issuedOn, string $templateName): self
    {
        return new self(
            $this->recipientId,
            $this->contextId,
            $issuedOn,
            $templateName,
            $this->reference,
            $this->emailedOn,
            $this->certificateId
        );
    }

    /**
     * This award with a freshly generated reference.
     *
     * Every link previously sent for this award stops working, which is what
     * makes reissuing a link a meaningful action rather than a duplicate one.
     *
     * The emailed date is cleared with it, deliberately. It answers "who still
     * needs telling?", and a recipient whose only link has just been revoked
     * needs telling again — keeping the old date would report them as informed
     * while leaving them holding something that no longer works.
     *
     * The certificate id is untouched: revocation is about links, and a
     * certificate already printed with its id must still match this record.
     *
     * @return self The award with a new reference and no send recorded
     */
    public function withNewReference(): self
    {
        return new self(
            $this->recipientId,
            $this->contextId,
            $this->issuedOn,
            $this->templateName,
            self::generateReference(),
            '',
            $this->certificateId
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
