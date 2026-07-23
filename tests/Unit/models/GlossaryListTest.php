<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\models;

use BSBI\WebBase\models\BaseFilter;
use BSBI\WebBase\models\GlossaryItem;
use BSBI\WebBase\models\GlossaryList;
use PHPUnit\Framework\TestCase;

final class GlossaryListTest extends TestCase
{
    private function makeList(): GlossaryList
    {
        $list = new GlossaryList();
        $list->addListItem(
            (new GlossaryItem('Bract', '/glossary/bract'))
                ->setDefinition('A modified leaf at the base of a flower.')
                ->setSlug('bract')
        );
        $list->addListItem(
            (new GlossaryItem('Petiole', '/glossary/petiole'))
                ->setDefinition('The stalk of a leaf.')
                ->setSlug('petiole')
        );
        return $list;
    }

    public function testEmptyListOnConstruct(): void
    {
        $list = new GlossaryList();

        $this->assertFalse($list->hasListItems());
        $this->assertSame(0, $list->count());
        $this->assertSame([], $list->getListItems());
    }

    public function testAddListItemAppendsAndReturnsSelf(): void
    {
        $list = new GlossaryList();
        $item = new GlossaryItem('Bract', '/glossary/bract');

        $result = $list->addListItem($item);

        $this->assertSame($list, $result);
        $this->assertSame(1, $list->count());
        $this->assertSame([$item], $list->getListItems());
    }

    public function testItemAndFilterTypes(): void
    {
        $list = new GlossaryList();

        $this->assertSame(GlossaryItem::class, $list->getItemType());
        $this->assertSame(BaseFilter::class, $list->getFilterType());
    }

    public function testGetTermsReturnsTitlesInOrder(): void
    {
        $this->assertSame(['Bract', 'Petiole'], $this->makeList()->getTerms());
    }

    public function testFindByTermIsCaseInsensitive(): void
    {
        $list = $this->makeList();

        $item = $list->findByTerm('bract');
        $this->assertNotNull($item);
        $this->assertSame('Bract', $item->getTitle());

        $this->assertNull($list->findByTerm('unknown'));
    }

    public function testFindByUrl(): void
    {
        $list = $this->makeList();

        $item = $list->findByUrl('/glossary/petiole');
        $this->assertNotNull($item);
        $this->assertSame('Petiole', $item->getTitle());

        $this->assertNull($list->findByUrl('/somewhere/else'));
    }

    public function testFindBySlug(): void
    {
        $list = $this->makeList();

        $item = $list->findBySlug('bract');
        $this->assertNotNull($item);
        $this->assertSame('Bract', $item->getTitle());

        $this->assertNull($list->findBySlug('missing'));
    }
}
