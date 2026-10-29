<?php

declare(strict_types=1);

namespace Core\Portal\Application\Support;

/**
 * The colour tokens the portal derives from a project's brand colour
 * (WCAG 2.2 AA, 1.4.3 / 1.4.11). Pure: no framework, no I/O.
 *
 * The brand colour is any `#rrggbb` chosen by the project, so nothing can be
 * assumed about it. Three tokens come out of it:
 *
 *  - `--brand-ink`: the text colour on a brand-coloured fill (white or slate,
 *    whichever contrasts more);
 *  - `--brand-text` (light): the brand darkened until it reads as text on the
 *    tinted surfaces of the light theme;
 *  - `--brand-text` (dark): the brand lightened for the dark theme.
 *
 * The surface values mirror `portal.css`; change one, change the other.
 */
final class PortalPalette
{
    public const FALLBACK = '#2563eb';

    private const INK_LIGHT = '#ffffff';

    private const INK_DARK = '#0f172a';

    private const TARGET_CONTRAST = 4.5;

    /**
     * Backgrounds the brand text can land on, light theme: the page, the card,
     * the muted fill. The 10 % tint (`--brand-soft`) is added per brand.
     *
     * @var list<string>
     */
    private const LIGHT_SURFACES = ['#ffffff', '#f5f6f8', '#eef1f5'];

    /** @var list<string> */
    private const DARK_SURFACES = ['#111a2c', '#0b1220', '#1a2640'];

    /** `--brand-soft` is `color-mix(in srgb, var(--brand) 10%, var(--surface))`. */
    private const TINT = 0.10;

    private function __construct() {}

    /** A strictly valid `#rrggbb`, lower-cased; the fallback otherwise. */
    public static function normalize(?string $hex): string
    {
        if ($hex === null || preg_match('/^#[0-9a-f]{6}$/i', $hex) !== 1) {
            return self::FALLBACK;
        }

        return strtolower($hex);
    }

    /** Text colour for a brand-coloured fill. */
    public static function ink(string $hex): string
    {
        $brand = self::rgb(self::normalize($hex));

        $onWhite = self::contrast($brand, self::rgb(self::INK_LIGHT));
        $onSlate = self::contrast($brand, self::rgb(self::INK_DARK));

        return $onWhite >= $onSlate ? self::INK_LIGHT : self::INK_DARK;
    }

    /** The brand as text on the light theme's surfaces. */
    public static function textLight(string $hex): string
    {
        return self::shift(self::normalize($hex), [0, 0, 0], self::LIGHT_SURFACES);
    }

    /** The brand as text on the dark theme's surfaces. */
    public static function textDark(string $hex): string
    {
        return self::shift(self::normalize($hex), [255, 255, 255], self::DARK_SURFACES);
    }

    /** The body of `/portal/theme.css` for a brand colour. */
    public static function css(?string $hex): string
    {
        $brand = self::normalize($hex);

        return ':root{--brand:'.$brand
            .';--brand-ink:'.self::ink($brand)
            .';--brand-text:'.self::textLight($brand).';}'
            .'@media (prefers-color-scheme:dark){:root{--brand-text:'.self::textDark($brand).';}}';
    }

    /**
     * Mix the brand toward $target until it holds 4.5:1 on every surface and
     * on the brand's own 10 % tint of each. Returns the brand itself when it
     * already does.
     *
     * @param array{int, int, int} $target
     * @param list<string> $surfaces
     */
    private static function shift(string $hex, array $target, array $surfaces): string
    {
        $brand = self::rgb($hex);

        $backgrounds = [];
        foreach ($surfaces as $surface) {
            $base = self::rgb($surface);
            $backgrounds[] = $base;
            $backgrounds[] = self::mix($base, $brand, self::TINT);
        }

        for ($step = 0; $step <= 100; $step++) {
            $candidate = self::mix($brand, $target, $step / 100);

            if (self::holds($candidate, $backgrounds)) {
                return self::hex($candidate);
            }
        }

        return self::hex($target);
    }

    /**
     * @param array{int, int, int} $colour
     * @param list<array{int, int, int}> $backgrounds
     */
    private static function holds(array $colour, array $backgrounds): bool
    {
        foreach ($backgrounds as $background) {
            if (self::contrast($colour, $background) < self::TARGET_CONTRAST) {
                return false;
            }
        }

        return true;
    }

    /**
     * $weight of $with, the rest of $from, per sRGB channel (what CSS
     * `color-mix(in srgb, ...)` does), rounded to a displayable channel.
     *
     * @param array{int, int, int} $from
     * @param array{int, int, int} $with
     * @return array{int, int, int}
     */
    private static function mix(array $from, array $with, float $weight): array
    {
        return [
            self::channel($from[0] + ($with[0] - $from[0]) * $weight),
            self::channel($from[1] + ($with[1] - $from[1]) * $weight),
            self::channel($from[2] + ($with[2] - $from[2]) * $weight),
        ];
    }

    private static function channel(float $value): int
    {
        return max(0, min(255, (int) round($value)));
    }

    /**
     * @param array{int, int, int} $a
     * @param array{int, int, int} $b
     */
    private static function contrast(array $a, array $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * WCAG relative luminance.
     *
     * @param array{int, int, int} $rgb
     */
    private static function luminance(array $rgb): float
    {
        $lin = static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $lin($rgb[0]) + 0.7152 * $lin($rgb[1]) + 0.0722 * $lin($rgb[2]);
    }

    /**
     * @return array{int, int, int}
     */
    private static function rgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }

    /**
     * @param array{int, int, int} $rgb
     */
    private static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }
}
