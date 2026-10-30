<?php

namespace KimaiPlugin\ClientReportBundle\Report;

/**
 * Decides whether a visit to a shared link is the client looking at it.
 *
 * Two kinds of visits are not: link preview bots, which fetch the page the moment a
 * link is pasted into Slack, Teams, WhatsApp and the like, and our own team checking
 * the page while logged in to Kimai. Counting either one sends the "client opened the
 * report" email before the client has seen anything (30.10.2026, Slackbot).
 */
final class VisitFilter
{
    private const PREVIEW_BOTS = [
        'slackbot',
        'slack-imgproxy',
        'facebookexternalhit',
        'facebot',
        'twitterbot',
        'linkedinbot',
        'whatsapp',
        'telegrambot',
        'discordbot',
        // Teams previews come as SkypeUriPreview; plain "Teams" is its in-app browser,
        // which is a real person and must be counted
        'skypeuripreview',
        'microsoftpreview',
        'google-pagerenderer',
        'embedly',
        'iframely',
        // generic markers last: Slackbot, Googlebot, Applebot, Mattermost-Bot and others
        'bot',
        'crawler',
        'spider',
        'preview',
    ];

    public static function isPreviewBot(?string $userAgent): bool
    {
        $userAgent = strtolower(trim((string) $userAgent));

        if ($userAgent === '') {
            return true;
        }

        foreach (self::PREVIEW_BOTS as $needle) {
            if (str_contains($userAgent, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function countsAsClientVisit(?string $userAgent, bool $loggedIn): bool
    {
        return !$loggedIn && !self::isPreviewBot($userAgent);
    }
}
