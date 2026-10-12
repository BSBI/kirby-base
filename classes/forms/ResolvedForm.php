<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms;

use BSBI\WebBase\forms\about\AboutSubject;
use Kirby\Cms\Blocks;

/**
 * An immutable value object carrying all data needed to render a definition-based
 * form in the form/definition-form snippet.
 *
 * Built from a FormPage model via FormProperties::getResolvedForm() and passed
 * directly to the snippet, decoupling snippet rendering from the page model.
 *
 * Opened from an about link, the questions the link answers are left out (a
 * section left with no questions goes too; a question the subject has no
 * value for is still asked); the snippet names the subject and posts its
 * signed value back.
 */
readonly class ResolvedForm
{
    /** @var array<ResolvedFormField|ResolvedFormSection> */
    public array $fieldGroups;

    /**
     * @param array<ResolvedFormField|ResolvedFormSection> $fieldGroups          Fixed form fields/sections from a BaseFormDefinition
     * @param Blocks|null                                  $customBlocks         Custom form element blocks added by panel editors
     * @param bool                                         $submissionSuccessful Whether the form was successfully submitted this request
     * @param string                                       $csrfToken            CSRF token to render as a hidden input
     * @param string                                       $introHtml            Rendered HTML to display before the form; hidden after submission
     * @param AboutSubject|null                            $about                What the feedback is about, from a verified about link
     * @param array<string, string>                        $fillFrom             Questions an about link can answer (key => 'title', 'date' or a detail)
     */
    public function __construct(
        array $fieldGroups,
        public readonly ?Blocks $customBlocks,
        public readonly bool $submissionSuccessful,
        public readonly string $csrfToken,
        public readonly string $introHtml = '',
        public readonly ?AboutSubject $about = null,
        array $fillFrom = [],
    ) {
        $answered = $about === null ? [] : $about->answered($fillFrom);
        $this->fieldGroups = $answered === [] ? $fieldGroups : self::leaveOut($fieldGroups, $answered);
    }

    /**
     * Returns the groups without the fields named, dropping a section left empty.
     *
     * @param array<ResolvedFormField|ResolvedFormSection> $groups
     * @param array<string, string>                        $names Keyed by field name
     * @return array<ResolvedFormField|ResolvedFormSection>
     */
    private static function leaveOut(array $groups, array $names): array
    {
        $kept = [];
        foreach ($groups as $group) {
            if ($group instanceof ResolvedFormField) {
                if (!isset($names[$group->name])) {
                    $kept[] = $group;
                }
                continue;
            }
            $fields = array_values(array_filter($group->fields, static fn(ResolvedFormField $field): bool => !isset($names[$field->name])));
            if ($fields === $group->fields) {
                $kept[] = $group;
            } elseif ($fields !== []) {
                $kept[] = new ResolvedFormSection($group->id, $group->title, $fields, $group->conditionField, $group->conditionValue);
            }
        }
        return $kept;
    }
}
