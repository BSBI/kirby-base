<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use Kirby\Cms\Blocks;
use Kirby\Cms\Page;

/**
 * Reads the fields of a library section page.
 *
 * A section page holds its fields in a `formFields` blocks field. A variation
 * also names a base section in its `extends` pages field: it gives the base's
 * fields first, then its own. The base is read live, so editing a base section
 * changes every variation of it (and every form using any of them).
 */
final readonly class PanelSectionReader
{
    /** Longest chain of variations followed before giving up. */
    public const MAX_DEPTH = 5;

    /**
     * @param SectionPageResolver $resolver    Finds pages named in `extends`
     * @param PanelFieldReader    $fieldReader Reads individual field blocks
     */
    public function __construct(
        private SectionPageResolver $resolver,
        private PanelFieldReader $fieldReader = new PanelFieldReader(),
    ) {
    }

    /**
     * Returns the section's fields in order: inherited first, then its own.
     * Keys are not checked for validity or uniqueness here; that is a form-level
     * concern (PanelFormDefinition).
     *
     * @param Page         $section  The library section page
     * @param FormProblems $problems Receives anything that had to be left out
     * @return PanelField[]
     */
    public function read(Page $section, FormProblems $problems): array
    {
        return $this->readChain($section, $problems, [], 0);
    }

    /**
     * Reads the fields of blocks directly, for sections defined inline on a form.
     *
     * @param Blocks       $blocks   Field blocks
     * @param string       $where    Editor-facing name of where the blocks are
     * @param FormProblems $problems Receives anything that had to be left out
     * @return PanelField[]
     */
    public function readBlocks(Blocks $blocks, string $where, FormProblems $problems): array
    {
        $fields = [];
        foreach ($blocks as $block) {
            $field = $this->fieldReader->read($block);
            if ($field === null) {
                $problems->add(sprintf(
                    '%s contains a "%s" block, which is not a form field; it has been left out.',
                    $where,
                    $block->type()
                ));
                continue;
            }
            $fields[] = $field;
        }
        return $fields;
    }

    /**
     * @param Page         $section
     * @param FormProblems $problems
     * @param string[]     $visited Ids of sections already on this chain
     * @param int          $depth
     * @return PanelField[]
     */
    private function readChain(Page $section, FormProblems $problems, array $visited, int $depth): array
    {
        $name = $this->nameOf($section);
        $visited[] = $section->id();

        $inherited = [];
        $reference = PanelContent::firstReference($section->content(), 'extends');
        if ($reference !== null) {
            $base = $this->resolver->resolve($reference);
            if ($base === null) {
                $problems->add(sprintf(
                    'Section "%s" is a variation of a section that cannot be found; only its own fields are used.',
                    $name
                ));
            } elseif (in_array($base->id(), $visited, true)) {
                $problems->add(sprintf(
                    'Section "%s" is a variation of "%s", which leads back to itself (a loop); the loop is ignored.',
                    $name,
                    $this->nameOf($base)
                ));
            } elseif ($depth >= self::MAX_DEPTH) {
                $problems->add(sprintf(
                    'Section "%s" is a variation nested deeper than %d levels; the deeper sections are ignored.',
                    $name,
                    self::MAX_DEPTH
                ));
            } else {
                $inherited = $this->readChain($base, $problems, $visited, $depth + 1);
            }
        }

        $own = $this->readBlocks(
            PanelContent::blocks($section->content(), 'formFields'),
            sprintf('Section "%s"', $name),
            $problems
        );

        return array_merge($inherited, $own);
    }

    /**
     * Returns an editor-facing name for a section page.
     *
     * @param Page $section
     */
    private function nameOf(Page $section): string
    {
        $title = PanelContent::text($section->content(), 'title');
        return $title !== '' ? $title : $section->slug();
    }
}
