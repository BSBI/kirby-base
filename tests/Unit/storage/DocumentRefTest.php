<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\storage;

use BSBI\WebBase\storage\DocumentRef;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The key layout is the audit requirement: course-first, owner by id, a
 * timestamped safe filename. Nothing user-supplied may reach a key unmade.
 */
final class DocumentRefTest extends TestCase
{
    private function ref(string $filename = 'QS unit 3 submitted.docx', string $kind = 'question-sheet'): DocumentRef
    {
        return new DocumentRef('Identiplant 2026', 'unit-3', 'abc123', $kind, $filename, new DateTimeImmutable('2026-06-25 14:03:02'));
    }

    public function testKeyIsCourseFirstThenUnitOwnerKindAndTimestampedName(): void
    {
        $this->assertSame(
            'documents/identiplant-2026/unit-3/abc123/question-sheet/2026-06-25-14-03-02-QS unit 3 submitted.docx',
            $this->ref()->key()
        );
    }

    public function testCoursePrefixCoversEveryDocumentOfACourse(): void
    {
        $this->assertSame('documents/identiplant-2026/', DocumentRef::coursePrefix('Identiplant 2026'));
        $this->assertStringStartsWith(DocumentRef::coursePrefix('Identiplant 2026'), $this->ref()->key());
    }

    public function testSegmentsAreSlugsWithNoSeparators(): void
    {
        $this->assertSame('a-b-c', DocumentRef::segment('A/B\\C'));
        $this->assertSame('unit-3', DocumentRef::segment('  Unit 3 '));
        $this->assertSame('', DocumentRef::segment('../'));
        $this->assertSame('x', DocumentRef::segment('.x.'));
    }

    public function testFilenamesLoseSeparatorsTraversalAndControlCharacters(): void
    {
        // basename() keeps the last segment only; what is left is a plain name
        $this->assertSame('passwd', DocumentRef::safeFilename('../../etc/passwd'));
        $this->assertSame('', DocumentRef::safeFilename('../..'));
        $this->assertSame('x', DocumentRef::safeFilename('..x..'));
        $this->assertSame('a-b.docx', DocumentRef::safeFilename("a\t\nb.docx")); // control characters become dashes
        $this->assertSame('report.pdf', DocumentRef::safeFilename('C:\\Users\\me\\report.pdf'));
        $this->assertSame('odd-name', DocumentRef::safeFilename(' odd:name? '));
        $this->assertSame('', DocumentRef::safeFilename('...'));
    }

    public function testEmptySegmentsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DocumentRef('', 'unit-3', 'abc', 'kind', 'a.docx', new DateTimeImmutable());
    }

    public function testAFilenameWithNothingSafeLeftIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->ref('///');
    }
}
