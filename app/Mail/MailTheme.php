<?php

namespace App\Mail;

/**
 * The design tokens every Novarr email shares (resources/views/emails/layout
 * and emails/components/*): the dark "Serial" frame of the daily summary.
 * Mail clients ignore stylesheets, so the views inline these values; one
 * place to read them keeps every mail on the same palette.
 *
 * Outlook has no rgba(): tinted fills are pre-composited against the
 * surface they sit on (#161A20).
 */
final class MailTheme
{
    public const GROUND = '#0F1216';   // page background, header, footer
    public const SURFACE = '#161A20';  // the body panel
    public const INSET = '#0F1216';    // a card inside the body panel
    public const TRACK = '#1C222A';    // progress-bar track
    public const BORDER = '#262D38';
    public const TEXT = '#E8EBF0';     // headings, titles
    public const BODY = '#D8DDE6';     // running text
    public const MUTED = '#8B95A5';    // labels, meta lines
    public const FAINT = '#6B7684';    // footer text
    public const DIM = '#5B6472';      // separators, zero figures
    public const ACCENT = '#6470FF';   // header rule, marks
    public const BUTTON = '#4F5CE6';   // filled buttons (white text holds 5:1)
    public const LINK = '#8EA2FF';
    public const AMBER = '#F0B429';
    public const SUCCESS = '#3FB950';
    public const DANGER = '#F05845';
    public const CYAN = '#4CC4D1';

    public const SANS = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    public const SERIF = "Georgia, 'Times New Roman', Times, serif"; // stands in for Literata
    public const MONO = "ui-monospace, SFMono-Regular, Menlo, Consolas, 'Courier New', monospace";

    public const TONES = ['info', 'success', 'warning', 'danger'];

    /** A tone's colour (an unknown tone is info). */
    public static function tone(?string $tone): string
    {
        return match ($tone) {
            'success' => self::SUCCESS,
            'warning' => self::AMBER,
            'danger' => self::DANGER,
            default => self::ACCENT,
        };
    }

    /** A tone's chip fill / border, pre-composited over SURFACE (12% / 35%). */
    public static function toneChip(?string $tone): array
    {
        return match ($tone) {
            'success' => ['#1A2B21', '#27532F'],
            'warning' => ['#302C21', '#625023'],
            'danger' => ['#33201F', '#6B3330'],
            default => ['#1F243B', '#31386E'],
        };
    }

    /** The running-text style (sans 15/1.6). */
    public static function body(): string
    {
        return 'font-family: ' . self::SANS . '; font-size: 15px; line-height: 1.6; color: ' . self::BODY . ';';
    }

    /** The small uppercase label style (section eyebrows, tile names). */
    public static function label(): string
    {
        return 'font-family: ' . self::SANS . '; font-size: 10px; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase;';
    }

    /** A mono status chip: full-value text on a 12% fill with a 35% border. */
    public static function chip(string $text, string $fill, string $border): string
    {
        return 'display: inline-block; font-family: ' . self::MONO . '; font-size: 10px; font-weight: 600; letter-spacing: 0.1em; '
            . 'text-transform: uppercase; color: ' . $text . '; background-color: ' . $fill . '; border: 1px solid ' . $border . '; '
            . 'padding: 2px 6px; white-space: nowrap; line-height: 14px; vertical-align: 2px;';
    }

    /** The chip for a tone. */
    public static function toneChipStyle(?string $tone): string
    {
        [$fill, $border] = self::toneChip($tone);

        return self::chip(self::tone($tone), $fill, $border);
    }

    /** A fixed-height spacer row (Outlook ignores margins on tables). */
    public static function spacer(int $height): string
    {
        return '<tr><td height="' . $height . '" style="height: ' . $height . 'px; font-size: 1px; line-height: 1px; mso-line-height-rule: exactly;">&nbsp;</td></tr>';
    }
}
