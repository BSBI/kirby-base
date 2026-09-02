<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\certificates;

use BSBI\WebBase\certificates\CertificateFontLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the bundled certificate font library.
 *
 * The claim that matters is that every advertised family resolves to a real,
 * readable definition file — a family offered in the Panel whose file is
 * missing fails at render time, when somebody is being awarded a certificate.
 */
final class CertificateFontLibraryTest extends TestCase
{
    /**
     * Verify every advertised family has a readable definition file.
     */
    public function testEveryAdvertisedFamilyResolvesToAFile(): void
    {
        foreach (array_keys(CertificateFontLibrary::FAMILIES) as $family) {
            $path = CertificateFontLibrary::fontFile($family);

            $this->assertNotSame('', $path, "Family '$family' has no definition file");
            $this->assertFileIsReadable($path);
        }
    }

    /**
     * Verify an unbundled family resolves to nothing, so TCPDF searches its own.
     */
    public function testAnUnbundledFamilyResolvesToNothing(): void
    {
        $this->assertSame('', CertificateFontLibrary::fontFile('dejavusans'));
    }

    /**
     * Verify a family that is not a plain word cannot reach the filesystem.
     *
     * The family originates in Panel-edited content, so a value shaped like a
     * path must be rejected before it is joined onto one.
     */
    public function testAPathShapedFamilyIsRejected(): void
    {
        $this->assertSame('', CertificateFontLibrary::fontFile('../../composer'));
        $this->assertSame('', CertificateFontLibrary::fontFile(''));
    }
}
