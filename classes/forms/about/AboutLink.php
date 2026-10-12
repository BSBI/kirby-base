<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\about;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Uuid\Uuid;
use Throwable;

/**
 * Links to a form that name what the feedback is about: `?about=<id>.<sig>`,
 * where id is the subject page's UUID (it survives renames and moves) and sig
 * a short HMAC over the form's and the subject's UUIDs, so editing the link
 * can't attribute feedback to another page.
 *
 * read() gives the subject only when the signature checks out, the page still
 * exists, and it is a kind the form accepts; anything else gives null, and the
 * form opens as if there were no link.
 *
 * The key is the `forms.aboutSecret` option; without one it is derived from the
 * site's location, which works but changes if the site moves. Changing the key
 * makes links already sent open without a subject.
 */
final readonly class AboutLink
{
    /** Hex characters of the HMAC kept in a link. */
    private const SIGNATURE_LENGTH = 16;

    private string $secret;

    public function __construct(App $kirby, private AboutKinds $kinds, ?string $secret = null)
    {
        $configured = $kirby->option('forms.aboutSecret');
        $this->secret = $secret
            ?? (is_string($configured) && $configured !== '' ? $configured : hash('sha256', 'about-links|' . $kirby->root('index')));
    }

    /**
     * Returns the full link to the form naming the subject.
     */
    public function link(Page $form, Page $subject): string
    {
        return $form->url() . '?about=' . rawurlencode($this->value($form, $subject));
    }

    /**
     * Returns the signed `about` value for the subject on this form.
     */
    public function value(Page $form, Page $subject): string
    {
        $id = $subject->uuid()->id();
        return $id . '.' . $this->signature($form, $id);
    }

    /**
     * Returns the subject a signed value names on this form, or null.
     */
    public function read(Page $form, string $value): ?Page
    {
        $parts = explode('.', trim($value));
        if (count($parts) !== 2 || $parts[0] === '' || preg_match('/^[A-Za-z0-9_-]+$/', $parts[0]) !== 1) {
            return null;
        }
        [$id, $signature] = $parts;
        if (!hash_equals($this->signature($form, $id), $signature)) {
            return null;
        }
        try {
            $subject = Uuid::for('page://' . $id)?->model();
        } catch (Throwable) {
            return null;
        }
        if (!$subject instanceof Page) {
            return null;
        }
        $kind = $this->kinds->kindOf($subject);
        return $kind !== null && in_array($kind, $this->kinds->forForm($form), true) ? $subject : null;
    }

    /**
     * Returns read()'s subject with its title and date snapshot, or null.
     */
    public function subject(Page $form, string $value): ?AboutSubject
    {
        $page = $this->read($form, $value);
        return $page === null ? null : new AboutSubject(
            $page->uuid()->toString(),
            $page->title()->toString(),
            $this->kinds->dateOf($page),
            trim($value),
            $this->kinds->labelOf($page),
            $this->kinds->endDateOf($page),
            $this->kinds->detailsOf($page)
        );
    }

    private function signature(Page $form, string $subjectId): string
    {
        return substr(hash_hmac('sha256', $form->uuid()->id() . '|' . $subjectId, $this->secret), 0, self::SIGNATURE_LENGTH);
    }
}
