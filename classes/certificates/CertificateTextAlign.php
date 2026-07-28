<?php

declare(strict_types=1);

namespace BSBI\WebBase\certificates;

/**
 * Horizontal alignment of a merged field within its box.
 *
 * Centre is the common case: most certificate fields sit on a centred line,
 * so the box spans the page and the text is centred within it. That keeps
 * long and short names looking equally deliberate.
 *
 * @package BSBI\WebBase
 */
enum CertificateTextAlign: string
{
    case Left = 'left';
    case Centre = 'centre';
    case Right = 'right';

    /**
     * The equivalent alignment flag used by TCPDF's Cell().
     *
     * @return string The TCPDF alignment flag
     */
    public function toTcpdfAlign(): string
    {
        return match ($this) {
            self::Left => 'L',
            self::Centre => 'C',
            self::Right => 'R',
        };
    }
}
