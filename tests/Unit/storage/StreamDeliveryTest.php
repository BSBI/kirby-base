<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\storage;

use BSBI\WebBase\storage\StreamDelivery;
use PHPUnit\Framework\TestCase;

/**
 * The plan behind a ranged download; send() is the few lines around it.
 */
final class StreamDeliveryTest extends TestCase
{
    public function testAFullResponseCarriesTypeDispositionLengthAndRangesOffer(): void
    {
        $plan = (new StreamDelivery())->plan(1000, null, 'QS unit 3.docx', 'application/msword', true);

        $this->assertSame(200, $plan['status']);
        $this->assertSame([0, 999], [$plan['from'], $plan['to']]);
        $this->assertSame('1000', $plan['headers']['Content-Length']);
        $this->assertSame('application/msword', $plan['headers']['Content-Type']);
        $this->assertSame('bytes', $plan['headers']['Accept-Ranges']);
        $this->assertStringStartsWith('attachment; filename="QS unit 3.docx"', $plan['headers']['Content-Disposition']);
        $this->assertSame('nosniff', $plan['headers']['X-Content-Type-Options']);
    }

    public function testInlineDispositionForViewingInTheBrowser(): void
    {
        $plan = (new StreamDelivery())->plan(10, null, 'photo.jpg', 'image/jpeg', false);

        $this->assertStringStartsWith('inline; filename="photo.jpg"', $plan['headers']['Content-Disposition']);
    }

    public function testAFilenameCannotInjectHeaders(): void
    {
        $plan = (new StreamDelivery())->plan(10, null, "a\"b\r\nX-Evil: 1.pdf", 'application/pdf', true);

        $this->assertStringNotContainsString("\n", $plan['headers']['Content-Disposition']);
        $this->assertStringContainsString('filename="abX-Evil: 1.pdf"', $plan['headers']['Content-Disposition']);
    }

    public function testRanges(): void
    {
        $delivery = new StreamDelivery();

        $tail = $delivery->plan(1000, 'bytes=900-', 'a.zip', 'application/zip', true);
        $this->assertSame(206, $tail['status']);
        $this->assertSame([900, 999], [$tail['from'], $tail['to']]);
        $this->assertSame('bytes 900-999/1000', $tail['headers']['Content-Range']);
        $this->assertSame('100', $tail['headers']['Content-Length']);

        $suffix = $delivery->plan(1000, 'bytes=-10', 'a.zip', 'application/zip', true);
        $this->assertSame([990, 999], [$suffix['from'], $suffix['to']]);

        $clamped = $delivery->plan(1000, 'bytes=0-5000', 'a.zip', 'application/zip', true);
        $this->assertSame([0, 999], [$clamped['from'], $clamped['to']]);

        $bad = $delivery->plan(1000, 'bytes=2000-', 'a.zip', 'application/zip', true);
        $this->assertSame(416, $bad['status']);
        $this->assertSame('bytes */1000', $bad['headers']['Content-Range']);

        $ignored = $delivery->plan(1000, 'items=0-1', 'a.zip', 'application/zip', true);
        $this->assertSame(200, $ignored['status']);
    }
}
