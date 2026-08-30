<?php

namespace KimaiPlugin\ClientReportBundle\Report;

/**
 * Logo and company name for the client facing report.
 *
 * The logo is embedded as a data URI rather than served from a route: the page has
 * to work for someone who is not logged in, and mPDF has to be able to draw it while
 * rendering the PDF from a standalone HTML string.
 *
 * To rebrand, drop a new file into Resources/assets/ and point LOGO_FILE at it.
 */
final class Branding
{
    private const COMPANY = 'Web Systems';
    private const LOGO_FILE = __DIR__ . '/../Resources/assets/logo.png';

    private ?string $logo = null;
    private bool $logoLoaded = false;

    public function getCompany(): string
    {
        return self::COMPANY;
    }

    public function getLogoDataUri(): ?string
    {
        if ($this->logoLoaded) {
            return $this->logo;
        }

        $this->logoLoaded = true;

        if (!is_readable(self::LOGO_FILE)) {
            return $this->logo = null;
        }

        $binary = file_get_contents(self::LOGO_FILE);
        if ($binary === false) {
            return $this->logo = null;
        }

        return $this->logo = 'data:image/png;base64,' . base64_encode($binary);
    }
}
