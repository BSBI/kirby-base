<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\certificates;

use BSBI\WebBase\certificates\CertificateException;
use BSBI\WebBase\certificates\CertificatePdfSupport;
use PHPUnit\Framework\TestCase;

/**
 * Tests the check that the PDF libraries are installed.
 *
 * They are no longer a hard requirement of this plugin: only the certificate
 * classes use them, and most consuming sites never render a certificate. A site
 * that does want to must install them itself, so the failure when it has not is
 * a first-class case rather than an accident, and it should say which packages
 * are missing.
 */
final class CertificatePdfSupportTest extends TestCase
{
    /**
     * Verify the real dependencies are present in this repository's own dev install.
     *
     * Also a canary: if this fails, the plugin's own test suite has lost the
     * libraries its certificate tests need.
     */
    public function testTheRealDependenciesAreAvailableHere(): void
    {
        $this->assertTrue(CertificatePdfSupport::isAvailable());

        CertificatePdfSupport::assertAvailable();
        $this->addToAssertionCount(1);
    }

    /**
     * Verify a missing library is reported rather than assumed present.
     */
    public function testAMissingLibraryIsReportedAsUnavailable(): void
    {
        $this->assertFalse(
            CertificatePdfSupport::isAvailable(['No\Such\Pdf\Library' => 'vendor/absent'])
        );
    }

    /**
     * Verify the failure names the package to install, not just the class.
     *
     * A "class not found" tells somebody what broke; the package name tells them
     * what to do about it, which is the whole reason this check exists.
     */
    public function testTheFailureNamesThePackageToInstall(): void
    {
        $this->expectException(CertificateException::class);
        $this->expectExceptionMessageMatches('/vendor\/absent/');

        CertificatePdfSupport::assertAvailable(['No\Such\Pdf\Library' => 'vendor/absent']);
    }

    /**
     * Verify every missing package is named, not only the first.
     *
     * Being told to install one package, doing so, and being told about the next
     * is a poor way to find out you needed two.
     */
    public function testEveryMissingPackageIsNamed(): void
    {
        try {
            CertificatePdfSupport::assertAvailable([
                'No\Such\Pdf\Library' => 'vendor/absent',
                'Also\Missing' => 'vendor/other',
            ]);
            $this->fail('expected a CertificateException');
        } catch (CertificateException $e) {
            $this->assertStringContainsString('vendor/absent', $e->getMessage());
            $this->assertStringContainsString('vendor/other', $e->getMessage());
        }
    }

    /**
     * Verify a package that is present is not named as missing.
     */
    public function testAPresentPackageIsNotReportedAsMissing(): void
    {
        try {
            CertificatePdfSupport::assertAvailable([
                'TCPDF' => 'tecnickcom/tcpdf',
                'No\Such\Pdf\Library' => 'vendor/absent',
            ]);
            $this->fail('expected a CertificateException');
        } catch (CertificateException $e) {
            $this->assertStringContainsString('vendor/absent', $e->getMessage());
            $this->assertStringNotContainsString('tecnickcom/tcpdf', $e->getMessage());
        }
    }
}
