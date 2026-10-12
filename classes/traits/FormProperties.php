<?php

namespace BSBI\WebBase\traits;

use BSBI\WebBase\forms\about\AboutSubject;
use BSBI\WebBase\forms\ResolvedForm;
use BSBI\WebBase\forms\ResolvedFormField;
use BSBI\WebBase\forms\ResolvedFormSection;
use Kirby\Cms\Blocks;

/**
 *
 */
trait FormProperties {

    use ErrorHandling;

    private string $csrfToken = '';

    private bool $submissionSuccessful = false;

    private string $introHtml = '';

    /** @var ResolvedFormField[] Fixed fields resolved from a BaseFormDefinition */
    private array $formFields = [];

    /** @var array<ResolvedFormField|ResolvedFormSection> Section-aware field groups from a BaseFormDefinition */
    private array $formFieldGroups = [];

    /** @var Blocks|null Custom form element blocks added by panel editors */
    private ?Blocks $customFormBlocks = null;

    private ?AboutSubject $aboutSubject = null;

    /** @var array<string, string> */
    private array $fillFromKeys = [];

    /**
     * @return string
     */
    public function getCSRFToken(): string
    {
        return $this->csrfToken;
    }

    /**
     * @param string $csrfToken
     * @return static
     */
    public function setCSRFToken(string $csrfToken): static
    {
        $this->csrfToken = $csrfToken;
        return $this;
    }

    private string $turnstileSiteKey;

    /**
     * @return string
     */
    public function getTurnstileSiteKey(): string
    {
        return $this->turnstileSiteKey;
    }

    /**
     * @param string $turnstileSiteKey
     * @return $this
     */
    public function setTurnstileSiteKey(string $turnstileSiteKey): static
    {
        $this->turnstileSiteKey = $turnstileSiteKey;
        return $this;
    }

    /**
     * @return bool
     */
    public function isSubmissionSuccessful(): bool
    {
        return $this->submissionSuccessful;
    }

    /**
     * @param bool $submissionSuccessful
     * @return $this
     */
    public function setSubmissionSuccessful(bool $submissionSuccessful): static
    {
        $this->submissionSuccessful = $submissionSuccessful;
        return $this;
    }

    /**
     * Returns the rendered HTML intro content to display before the form.
     *
     * @return string
     */
    public function getIntroHtml(): string
    {
        return $this->introHtml;
    }

    /**
     * Sets the rendered HTML intro content to display before the form.
     *
     * @param string $introHtml
     * @return $this
     */
    public function setIntroHtml(string $introHtml): static
    {
        $this->introHtml = $introHtml;
        return $this;
    }

    /**
     * Returns the resolved fixed form fields set by BaseFormDefinition::getFields().
     *
     * @return ResolvedFormField[]
     */
    public function getFormFields(): array
    {
        return $this->formFields;
    }

    /**
     * @param ResolvedFormField[] $formFields
     * @return static
     */
    public function setFormFields(array $formFields): static
    {
        $this->formFields = $formFields;
        return $this;
    }

    /**
     * Returns the section-aware field groups set by BaseFormDefinition::getFieldGroups().
     *
     * @return array<ResolvedFormField|ResolvedFormSection>
     */
    public function getFormFieldGroups(): array
    {
        return $this->formFieldGroups;
    }

    /**
     * @param array<ResolvedFormField|ResolvedFormSection> $formFieldGroups
     * @return static
     */
    public function setFormFieldGroups(array $formFieldGroups): static
    {
        $this->formFieldGroups = $formFieldGroups;
        return $this;
    }

    /**
     * Returns the custom form element blocks added by panel editors.
     *
     * @return Blocks|null
     */
    public function getCustomFormBlocks(): ?Blocks
    {
        return $this->customFormBlocks;
    }

    /**
     * @param Blocks $customFormBlocks
     * @return static
     */
    public function setCustomFormBlocks(Blocks $customFormBlocks): static
    {
        $this->customFormBlocks = $customFormBlocks;
        return $this;
    }

    /**
     * Returns what the feedback is about (a verified about link), or null.
     */
    public function getAboutSubject(): ?AboutSubject
    {
        return $this->aboutSubject;
    }

    /**
     * Sets what the feedback is about, and the questions that answers
     * (key => 'title' or 'date'), which the form then leaves out.
     *
     * @param array<string, string> $fillFromKeys
     */
    public function setAboutSubject(?AboutSubject $aboutSubject, array $fillFromKeys = []): static
    {
        $this->aboutSubject = $aboutSubject;
        $this->fillFromKeys = $fillFromKeys;
        return $this;
    }

    /**
     * Returns an immutable ResolvedForm value object containing all data
     * needed to render the form snippet, decoupled from the page model.
     *
     * @return ResolvedForm
     */
    public function getResolvedForm(): ResolvedForm
    {
        return new ResolvedForm(
            fieldGroups:          $this->formFieldGroups,
            customBlocks:         $this->customFormBlocks,
            submissionSuccessful: $this->submissionSuccessful,
            csrfToken:            $this->csrfToken,
            introHtml:            $this->introHtml,
            about:                $this->aboutSubject,
            fillFrom:             $this->fillFromKeys,
        );
    }
}

