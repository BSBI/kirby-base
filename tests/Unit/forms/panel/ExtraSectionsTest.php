<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\forms\panel;

use BSBI\WebBase\forms\BaseFormDefinition;
use BSBI\WebBase\forms\FormFieldSpec;
use BSBI\WebBase\forms\FormSection;
use BSBI\WebBase\forms\panel\PanelFormDefinition;
use BSBI\WebBase\forms\ResolvedFormField;
use BSBI\WebBase\forms\ResolvedFormSection;
use BSBI\WebBase\Testing\KirbyTestEnvironment;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Extra panel sections spliced into a hand-written form
 * (BaseFormDefinition::withExtraSections()).
 */
final class ExtraSectionsTest extends TestCase
{
    use PanelFormFixtures;

    public static function setUpBeforeClass(): void
    {
        KirbyTestEnvironment::boot('kirby-base-extra-sections-' . uniqid());
    }

    public function testWithoutExtrasTheFormIsUnchanged(): void
    {
        $page = $this->formPage([], 'extraSections');
        $plain = $this->handWritten();
        $withNone = $this->handWritten()->withExtraSections($this->extras($page));

        $this->assertEquals($plain->getFieldGroups($page), $withNone->getFieldGroups($page));
        $this->assertSame($plain->getFieldNames(), $withNone->getFieldNames());
        $this->assertSame([], $withNone->extraSubmissionColumns());
    }

    public function testExtrasGoAtTheEndByDefault(): void
    {
        $page = $this->pageWithOneExtra();
        $definition = $this->handWritten()->withExtraSections($this->extras($page));

        $this->assertSame(['name', 'email', 'goals', 'diet'], $definition->getFieldNames());
        $this->assertSame(['name', 'email', 'goals', 'diet'], array_map(
            static fn(ResolvedFormField $f): string => $f->name,
            $definition->getFields($page)
        ));
        $groups = $definition->getFieldGroups($page);
        $this->assertCount(3, $groups);
        $this->assertInstanceOf(ResolvedFormSection::class, $groups[2]);
        $this->assertSame('Extras', $groups[2]->title);
    }

    public function testExtrasGoAtTheSlotTheDefinitionNames(): void
    {
        $page = $this->pageWithOneExtra();
        $definition = $this->handWritten(slot: 1)->withExtraSections($this->extras($page));

        $this->assertSame(['name', 'email', 'diet', 'goals'], $definition->getFieldNames());
        $groups = $definition->getFieldGroups($page);
        $this->assertInstanceOf(ResolvedFormSection::class, $groups[1]);
        $this->assertSame('Extras', $groups[1]->title);
    }

    public function testExtraSubmissionColumnsAreTheExtrasOnly(): void
    {
        $page = $this->pageWithOneExtra();
        $definition = $this->handWritten()->withExtraSections($this->extras($page));

        $this->assertSame(
            ['diet' => ['label' => 'Dietary needs', 'column' => 'diet']],
            $definition->extraSubmissionColumns()
        );
        $this->assertSame([], $definition->getSubmissionColumns());
    }

    public function testAnExtraClashingWithAFixedKeyIsLeftOut(): void
    {
        $page = $this->formPage([
            $this->sectionInline([
                $this->blockData('form-textbox', ['label' => 'Email again', 'name' => 'email']),
                $this->blockData('form-textarea', ['label' => 'Dietary needs', 'name' => 'diet']),
            ], title: 'Extras'),
        ], 'extraSections');
        $fixed = $this->handWritten();
        $extras = $this->extras($page, $fixed->getFieldNames());
        $definition = $fixed->withExtraSections($extras);

        $this->assertSame(['name', 'email', 'goals', 'diet'], $definition->getFieldNames());
        $this->assertStringContainsString('"email"', $extras->validate()[0]);
    }

    private function pageWithOneExtra(): Page
    {
        return $this->formPage([
            $this->sectionInline(
                [$this->blockData('form-textarea', ['label' => 'Dietary needs', 'name' => 'diet'])],
                title: 'Extras'
            ),
        ], 'extraSections');
    }

    /**
     * @param list<string> $reservedKeys
     */
    private function extras(Page $page, array $reservedKeys = []): PanelFormDefinition
    {
        return new PanelFormDefinition($page, 'hand_written', $this->resolver([]), 'extraSections', $reservedKeys);
    }

    /**
     * A hand-written form: a section of two fields, then a loose field.
     */
    private function handWritten(?int $slot = null): BaseFormDefinition
    {
        return new class ($slot) extends BaseFormDefinition {
            public function __construct(private readonly ?int $slot)
            {
            }

            public function getFormType(): string
            {
                return 'hand_written';
            }

            /** @return array<FormFieldSpec|FormSection> */
            protected function defineForm(): array
            {
                return [
                    FormSection::make('about', 'About you')->fields(
                        FormFieldSpec::textbox('name', 'Name'),
                        FormFieldSpec::textbox('email', 'Email', 'email'),
                    ),
                    FormFieldSpec::textarea('goals', 'Your goals'),
                ];
            }

            protected function extraSectionsAt(): ?int
            {
                return $this->slot;
            }
        };
    }
}
