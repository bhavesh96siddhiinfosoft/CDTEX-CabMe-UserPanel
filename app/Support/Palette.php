<?php

namespace App\Support;

/**
 * Builds a full 50–900 brand scale from the single colour the admin picks in
 * Web Settings.
 *
 * The scale is computed here rather than with CSS color-mix() so the values are
 * deterministic and identical in every browser. Tailwind 4 compiles utilities
 * like `bg-brand-600` down to `var(--color-brand-600)`, so overriding those
 * custom properties at runtime re-themes every utility without a rebuild.
 */
class Palette
{
    /** Share of the base colour at each step; the rest is white. 600 is the base. */
    private const TINTS = [
        50 => 0.06,
        100 => 0.12,
        200 => 0.24,
        300 => 0.42,
        400 => 0.65,
        500 => 0.85,
    ];

    /** How far each step is darkened toward black. */
    private const SHADES = [
        700 => 0.12,
        800 => 0.26,
        900 => 0.40,
    ];

    public function __construct(private readonly string $base)
    {
    }

    public static function fromHex(?string $hex, string $fallback = '#d92662'): self
    {
        return new self(self::normalise($hex) ?? $fallback);
    }

    /** @return array<int|string, string> the scale plus contrast and rgb helpers */
    public function toArray(): array
    {
        [$r, $g, $b] = $this->rgb($this->base);

        $scale = [];

        foreach (self::TINTS as $step => $weight) {
            $scale[$step] = $this->hex(
                (int) round($r * $weight + 255 * (1 - $weight)),
                (int) round($g * $weight + 255 * (1 - $weight)),
                (int) round($b * $weight + 255 * (1 - $weight)),
            );
        }

        $scale[600] = $this->base;

        foreach (self::SHADES as $step => $amount) {
            $scale[$step] = $this->hex(
                (int) round($r * (1 - $amount)),
                (int) round($g * (1 - $amount)),
                (int) round($b * (1 - $amount)),
            );
        }

        return $scale;
    }

    /**
     * Black or white, whichever stays readable on the base colour. Uses
     * perceived brightness so a light brand pick doesn't get white text.
     */
    public function contrast(): string
    {
        [$r, $g, $b] = $this->rgb($this->base);

        return (($r * 299 + $g * 587 + $b * 114) / 1000) >= 150 ? '#1f2937' : '#ffffff';
    }

    /** "217, 38, 98" — for rgb()/rgba() interpolation in plain CSS. */
    public function rgbTriplet(): string
    {
        return implode(', ', $this->rgb($this->base));
    }

    public function base(): string
    {
        return $this->base;
    }

    /** The `:root` declarations that override the compiled Tailwind theme. */
    public function cssVariables(): string
    {
        $lines = [];
        $scale = $this->toArray();

        foreach ($scale as $step => $value) {
            $lines[] = "--color-brand-{$step}:{$value}";
        }

        $lines[] = '--brand-contrast:'.$this->contrast();
        $lines[] = '--brand-rgb:'.$this->rgbTriplet();

        $darkText = $this->contrast() === '#ffffff' ? $scale[200] : $scale[300];
        $lines[] = '--brand-text-dark:'.$darkText;
        $lines[] = '--brand-text-light:'.$scale[600];

        return implode(';', $lines).';';
    }

    /** Accepts #abc, #aabbcc or bare hex; returns #aabbcc or null. */
    private static function normalise(?string $hex): ?string
    {
        $hex = ltrim(trim((string) $hex), '#');

        if (strlen($hex) === 3 && ctype_xdigit($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return (strlen($hex) === 6 && ctype_xdigit($hex)) ? '#'.strtolower($hex) : null;
    }

    /** @return array{0:int,1:int,2:int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private function hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x',
            max(0, min(255, $r)),
            max(0, min(255, $g)),
            max(0, min(255, $b)),
        );
    }
}
