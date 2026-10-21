<?php

namespace Tests\Unit;

use App\Support\Palette;
use PHPUnit\Framework\TestCase;

class PaletteTest extends TestCase
{
    public function test_the_base_colour_lands_on_step_600(): void
    {
        $this->assertSame('#d92662', Palette::fromHex('#d92662')->toArray()[600]);
    }

    public function test_it_produces_the_full_scale(): void
    {
        $scale = Palette::fromHex('#d92662')->toArray();

        $this->assertSame([50, 100, 200, 300, 400, 500, 600, 700, 800, 900], array_keys($scale));

        foreach ($scale as $step => $value) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $value, "step $step");
        }
    }

    public function test_tints_get_lighter_and_shades_get_darker(): void
    {
        $scale = Palette::fromHex('#d92662')->toArray();

        $luma = fn (string $hex) => array_sum([
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
        ]);

        // Brightness must fall monotonically from 50 through 900.
        $previous = PHP_INT_MAX;
        foreach ($scale as $step => $value) {
            $current = $luma($value);
            $this->assertLessThan($previous, $current, "step $step should be darker than the one before");
            $previous = $current;
        }
    }

    public function test_contrast_flips_with_brightness(): void
    {
        // Dark brand → white text; light brand → near-black text.
        $this->assertSame('#ffffff', Palette::fromHex('#d92662')->contrast());
        $this->assertSame('#1f2937', Palette::fromHex('#ffd400')->contrast());
    }

    public function test_it_accepts_shorthand_and_bare_hex(): void
    {
        $this->assertSame('#aabbcc', Palette::fromHex('#abc')->base());
        $this->assertSame('#d92662', Palette::fromHex('d92662')->base());
    }

    public function test_it_falls_back_when_the_admin_colour_is_unusable(): void
    {
        foreach ([null, '', 'not-a-colour', '#12345'] as $bad) {
            $this->assertSame('#d92662', Palette::fromHex($bad)->base());
        }
    }

    public function test_css_variables_cover_every_step(): void
    {
        $css = Palette::fromHex('#0fa7c2')->cssVariables();

        foreach ([50, 100, 200, 300, 400, 500, 600, 700, 800, 900] as $step) {
            $this->assertStringContainsString("--color-brand-{$step}:", $css);
        }

        $this->assertStringContainsString('--brand-contrast:', $css);
        $this->assertStringContainsString('--brand-rgb:15, 167, 194', $css);
    }
}
