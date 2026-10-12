<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use Kirby\Cms\Page;

/**
 * Content index definition for form submissions.
 *
 * Indexes all form_submission pages site-wide so the panel Forms tab
 * can display counts and export links without loading all Kirby pages
 * into memory via site()->index(). `about` is the page:// UUID of what a
 * response is about (from an about link), blank for none.
 *
 * @package BSBI\WebBase\helpers
 */
class FormSubmissionIndexDefinition extends ContentIndexDefinition
{
    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'form_submissions';
    }

    /**
     * {@inheritDoc}
     */
    public function getCollectionName(): string
    {
        return 'formSubmissions';
    }

    /**
     * {@inheritDoc}
     */
    public function getTemplates(): array
    {
        return ['form_submission'];
    }

    /**
     * {@inheritDoc}
     */
    public function getColumns(): array
    {
        return [
            'form_type'    => 'TEXT NOT NULL DEFAULT ""',
            'submitted_at' => 'TEXT NOT NULL DEFAULT ""',
            'about'        => 'TEXT NOT NULL DEFAULT ""',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getIndexes(): array
    {
        return [
            'CREATE INDEX IF NOT EXISTS idx_form_submissions_form_type'
                . ' ON content_form_submissions (form_type)',
            'CREATE INDEX IF NOT EXISTS idx_form_submissions_submitted_at'
                . ' ON content_form_submissions (submitted_at)',
            'CREATE INDEX IF NOT EXISTS idx_form_submissions_about'
                . ' ON content_form_submissions (about)',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getRowData(Page $page, KirbyBaseHelper $helper): array
    {
        $formType = $helper->getPageFieldAsString($page, 'form_type');
        $submittedAt = $page->modified('Y-m-d H:i:s') ?? date('Y-m-d H:i:s');

        return [
            'page_id'      => $page->id(),
            'form_type'    => $formType,
            'submitted_at' => $submittedAt,
            'about'        => $helper->getPageFieldAsString($page, 'about'),
        ];
    }
}
