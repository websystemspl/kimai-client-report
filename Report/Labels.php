<?php

namespace KimaiPlugin\ClientReportBundle\Report;

/**
 * Wording for the public page. The client never logs in, so there is no user
 * preference to read - the language is picked when the link is created.
 *
 * Kept as a plain array instead of a translation catalogue: it is one screen worth
 * of strings and this way the plugin needs no catalogue registration.
 */
final class Labels
{
    private const STRINGS = [
        'en' => [
            'report' => 'Time report',
            'period' => 'Period',
            'total_hours' => 'Total hours',
            'billable_hours' => 'Billable hours',
            'not_billed' => 'Not billed',
            'avg_daily' => 'Average daily',
            'entries' => 'Time entries',
            'date' => 'Date',
            'time' => 'Time',
            'description' => 'Description',
            'person' => 'Member',
            'work_type' => 'Type of work',
            'duration' => 'Duration',
            'no_description' => 'No description',
            'no_charge' => 'no charge',
            'by_person' => 'Billable hours by person',
            'by_work_type' => 'Billable hours by type of work',
            'download_pdf' => 'Download PDF',
            'generated' => 'Generated',
            'empty' => 'No time was tracked in this period.',
            'gone_title' => 'This link is no longer active',
            'gone_body' => 'The report is not available any more. Please ask for a new link.',
            'days_worked' => 'Days worked',
        ],
        'pl' => [
            'report' => 'Raport czasu pracy',
            'period' => 'Okres',
            'total_hours' => 'Godziny razem',
            'billable_hours' => 'Godziny płatne',
            'not_billed' => 'Nieobciążone',
            'avg_daily' => 'Średnio dziennie',
            'entries' => 'Wpisy',
            'date' => 'Data',
            'time' => 'Godziny',
            'description' => 'Opis',
            'person' => 'Osoba',
            'work_type' => 'Rodzaj pracy',
            'duration' => 'Czas',
            'no_description' => 'Bez opisu',
            'no_charge' => 'nieodpłatne',
            'by_person' => 'Godziny płatne wg osób',
            'by_work_type' => 'Godziny płatne wg rodzaju pracy',
            'download_pdf' => 'Pobierz PDF',
            'generated' => 'Wygenerowano',
            'empty' => 'W tym okresie nie zapisano czasu pracy.',
            'gone_title' => 'Ten link jest już nieaktywny',
            'gone_body' => 'Raport nie jest dostępny. Poproś o nowy link.',
            'days_worked' => 'Dni pracy',
        ],
    ];

    /**
     * @return array<string, string>
     */
    public static function for(string $locale): array
    {
        return self::STRINGS[$locale] ?? self::STRINGS['en'];
    }

    /**
     * @return array<string, string>
     */
    public static function available(): array
    {
        return ['English' => 'en', 'Polski' => 'pl'];
    }
}
