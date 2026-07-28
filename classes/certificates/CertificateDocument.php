<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * A PDF document configured to draw nothing except what it is asked to.
 *
 * Two defaults are overridden. TCPDF stamps "Powered by TCPDF (www.tcpdf.org)"
 * onto the last page of every document at 1pt in invisible render mode; it does
 * not show in the artwork but sits in the text layer, so it appears whenever
 * anyone copies text out of a certificate. TCPDF also adds headers, footers,
 * margins and automatic page breaks, any of which would either mark the design
 * or silently append a blank page when a field is drawn near the edge.
 *
 * The suppression flag is protected with no setter, which is why this subclass
 * exists rather than a couple of calls in the renderer.
 *
 * @package BSBI\WebBase
 */
final class CertificateDocument extends Fpdi
{
    /**
     * @param string $orientation The page orientation, 'P' or 'L'
     * @param string $unit The measurement unit; points throughout, to match how
     *                     PDF coordinates and font sizes are expressed
     */
    public function __construct(string $orientation = 'L', string $unit = 'pt')
    {
        parent::__construct($orientation, $unit);

        $this->tcpdflink = false;

        $this->setPrintHeader(false);
        $this->setPrintFooter(false);
        $this->SetAutoPageBreak(false);
        $this->SetMargins(0, 0, 0);
        $this->setCellPaddings(0, 0, 0, 0);
        $this->setCellMargins(0, 0, 0, 0);
        $this->SetCreator('BSBI Capsella');
    }
}
