<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

use BSBI\WebBase\forms\about\AboutSubject;
use Kirby\Cms\Page;
use Kirby\Data\Data;

/**
 * Saves a response as a listed form_submission child of the form page.
 *
 * Given session slots (a form with "update within session" on), it remembers
 * each response it saves, per form page and form type, and a later save in
 * the same session updates that response instead of adding another. A
 * remembered response that has since gone is replaced by a new one.
 *
 * A response about a subject (from an about link) records the subject, and
 * has a slot per subject, so feedback on two events is two responses.
 *
 * A response's title is its submission time, then what it is about when that
 * is known (FormSubmissionTitle), so responses can be told apart in the panel;
 * updating a response renames that part too, unless an editor has renamed it.
 */
final readonly class FormSubmissionWriter
{
    /**
     * @param SessionSlots|null $slots Where saved responses are remembered; null saves a new one every time
     */
    public function __construct(private ?SessionSlots $slots = null)
    {
    }

    /**
     * Saves the items as a response of the given type and returns its page.
     *
     * @param Page                                                                $form     The form page
     * @param string                                                              $formType Stored on the response; blank for none
     * @param list<array{question: string, answer: string, key?: string, column?: string}> $items
     * @param AboutSubject|null                                                   $about    What the response is about, if anything
     * @param string $describedAs What the title names after the time
     *                            (FormSubmissionTitle::describe()); blank for none
     * @return Page
     */
    public function write(
        Page $form,
        string $formType,
        array $items,
        ?AboutSubject $about = null,
        string $describedAs = ''
    ): Page
    {
        $submission = Data::encode($items, 'yaml');
        $slot = 'form_submission_' . $form->id() . '_' . $formType . ($about !== null ? '_' . $about->uuid : '');

        $existing = $this->slots?->get($slot);
        $page = $existing !== null ? $form->findPageOrDraft($existing) : null;

        if ($page instanceof Page) {
            $content = ['submission' => $submission] + ($about?->fields() ?? []);
            // Renamed in the panel: the editor's title stays.
            $title = $page->title()->toString();
            if (str_starts_with($title, FormSubmissionSlug::TITLE_PREFIX)) {
                $content['title'] = FormSubmissionTitle::with($title, $describedAs);
            }
            $saved = $form->kirby()->impersonate('kirby', static fn(): Page => $page->update($content));
        } else {
            $name = FormSubmissionSlug::next(
                date('M-j-H.i.s'),
                static fn(string $slug): bool => $form->findPageOrDraft($slug) !== null
            );
            $content = [
                'title'      => FormSubmissionTitle::with($name['title'], $describedAs),
                'submission' => $submission,
            ];
            if ($formType !== '') {
                $content['form_type'] = $formType;
            }
            $content += $about?->fields() ?? [];
            $saved = $form->kirby()->impersonate('kirby', static fn(): Page => $form->createChild([
                'slug'     => $name['slug'],
                'template' => 'form_submission',
                'content'  => $content,
            ])->changeStatus('listed'));
        }

        if (!$saved instanceof Page) {
            throw new \RuntimeException('The response could not be saved under ' . $form->id());
        }
        $this->slots?->set($slot, $saved->slug());
        return $saved;
    }
}
