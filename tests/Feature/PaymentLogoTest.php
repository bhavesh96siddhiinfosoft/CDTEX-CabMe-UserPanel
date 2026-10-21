<?php

namespace Tests\Feature;

use App\Support\PaymentLogos;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The admin's payment logos are uploaded by hand and share nothing: some are
 * a small mark adrift in a 512² canvas, others a tightly cropped 6:1
 * wordmark. Dropped into equal boxes they look wildly different sizes, so
 * each is redrawn onto one canvas at a constant area.
 *
 * What must hold: padding is trimmed, every output is the same canvas, and a
 * logo that cannot be processed never breaks the page — it just falls back to
 * the admin's own file.
 */
class PaymentLogoTest extends TestCase
{
    public array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /** A $canvas² PNG holding a $mark² black square in the top-left corner. */
    private function paddedPng(int $canvas, int $mark): string
    {
        $image = imagecreatetruecolor($canvas, $canvas);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 0, 0, $mark - 1, $mark - 1, imagecolorallocate($image, 0, 0, 0));

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function pathFor(string $remote): string
    {
        $path = public_path('cache/payment-logos/pm-'.md5($remote).'.png');
        $this->written[] = $path;

        return $path;
    }

    /** The drawn logo's bounds inside the generated canvas. */
    private function drawnBox(string $path): array
    {
        $image = imagecreatefrompng($path);
        imagealphablending($image, false);

        $minX = PHP_INT_MAX; $minY = PHP_INT_MAX; $maxX = -1; $maxY = -1;

        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 100) {
                    continue;
                }
                $minX = min($minX, $x); $maxX = max($maxX, $x);
                $minY = min($minY, $y); $maxY = max($maxY, $y);
            }
        }

        imagedestroy($image);

        return [$maxX - $minX + 1, $maxY - $minY + 1];
    }

    public function test_a_mark_adrift_in_a_huge_canvas_is_trimmed_and_scaled_up(): void
    {
        $remote = 'https://admin.test/assets/tiny-mark.png';
        $path = $this->pathFor($remote);
        @unlink($path);

        // 20px of art in a 400px canvas — 5% of the file is the logo.
        Http::fake([$remote => Http::response($this->paddedPng(400, 20), 200)]);

        $url = app(PaymentLogos::class)->url($remote);

        $this->assertStringContainsString('cache/payment-logos/', $url);
        $this->assertFileExists($path);

        // Every logo lands on the same canvas...
        $this->assertSame([96, 96], array_slice(getimagesize($path), 0, 2));

        // ...and the padding is gone: a square mark is drawn at the target
        // size (76), not at the 20px it occupied in the original file.
        [$width, $height] = $this->drawnBox($path);
        $this->assertEqualsWithDelta(76, $width, 3);
        $this->assertEqualsWithDelta(76, $height, 3);
    }

    public function test_a_wider_mark_and_a_square_mark_cover_the_same_area(): void
    {
        $square = 'https://admin.test/assets/square.png';
        $wide = 'https://admin.test/assets/wide.png';
        $squarePath = $this->pathFor($square);
        $widePath = $this->pathFor($wide);
        @unlink($squarePath);
        @unlink($widePath);

        // A 1.4:1 mark, drawn tight against its canvas.
        $strip = imagecreatetruecolor(140, 100);
        imagesavealpha($strip, true);
        imagefill($strip, 0, 0, imagecolorallocatealpha($strip, 0, 0, 0, 127));
        imagefilledrectangle($strip, 0, 0, 139, 99, imagecolorallocate($strip, 0, 0, 0));
        ob_start();
        imagepng($strip);
        imagedestroy($strip);
        $wideBytes = (string) ob_get_clean();

        Http::fake([
            $square => Http::response($this->paddedPng(200, 100), 200),
            $wide => Http::response($wideBytes, 200),
        ]);

        app(PaymentLogos::class)->url($square);
        app(PaymentLogos::class)->url($wide);

        [$sw, $sh] = $this->drawnBox($squarePath);
        [$ww, $wh] = $this->drawnBox($widePath);

        // Equal AREA, not equal box: the wider mark ends up wider and shorter,
        // but both cover the same amount of ink, which is what reads as "the
        // same size" to the eye.
        $this->assertGreaterThan($sw, $ww);
        $this->assertLessThan($sh, $wh);
        $this->assertEqualsWithDelta(sqrt($sw * $sh), sqrt($ww * $wh), 6);
    }

    public function test_an_extreme_wordmark_is_clamped_inside_the_canvas(): void
    {
        $remote = 'https://admin.test/assets/banner.png';
        $path = $this->pathFor($remote);
        @unlink($path);

        // 8:1 — far wider than the square canvas can hold at target size.
        $strip = imagecreatetruecolor(800, 100);
        imagesavealpha($strip, true);
        imagefill($strip, 0, 0, imagecolorallocatealpha($strip, 0, 0, 0, 127));
        imagefilledrectangle($strip, 0, 0, 799, 99, imagecolorallocate($strip, 0, 0, 0));
        ob_start();
        imagepng($strip);
        imagedestroy($strip);

        Http::fake([$remote => Http::response((string) ob_get_clean(), 200)]);

        app(PaymentLogos::class)->url($remote);

        // Scaled down to fit rather than spilling over the canvas, and its
        // proportions survive.
        [$width, $height] = $this->drawnBox($path);
        $this->assertLessThanOrEqual(96, $width);
        $this->assertLessThanOrEqual(96, $height);
        $this->assertEqualsWithDelta(8, $width / $height, 0.6);
    }

    public function test_every_gateway_uses_the_shared_icon_set_untouched(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('', 500)]);

        // One set, one look — and served at its own size rather than redrawn,
        // which is what kept 38px artwork from being scaled up into mush.
        foreach (['Strip' => 'stripe.png', 'Razorpay' => 'razorpay.png', 'Xendit' => 'xendit.png'] as $label => $file) {
            $url = app(PaymentLogos::class)->forMethod($label, 'https://admin.test/assets/whatever.png');

            $this->assertStringEndsWith('images/payments/'.$file, $url);
            $this->assertStringNotContainsString('cache/payment-logos', $url);
        }

        // Nothing was fetched or rewritten to decide that.
        Http::assertNothingSent();
    }

    public function test_a_method_with_no_bundled_icon_keeps_the_admin_upload(): void
    {
        $remote = 'https://admin.test/assets/cash.png';
        $path = $this->pathFor($remote);
        @unlink($path);

        Http::fake([$remote => Http::response($this->paddedPng(300, 60), 200)]);

        // Cash and Wallet are not gateways, so they keep the admin's artwork
        // — normalised onto the same canvas as everything else.
        $url = app(PaymentLogos::class)->forMethod('Cash', $remote);

        $this->assertStringContainsString('cache/payment-logos/', $url);
        $this->assertSame([96, 96], array_slice(getimagesize($path), 0, 2));
    }

    public function test_an_unreachable_logo_falls_back_to_the_admin_file(): void
    {
        $remote = 'https://admin.test/assets/missing.png';
        Cache::flush();

        Http::fake([$remote => Http::response('', 500)]);

        $this->assertSame($remote, app(PaymentLogos::class)->url($remote));
    }

    public function test_a_broken_logo_is_not_refetched_on_every_page(): void
    {
        $remote = 'https://admin.test/assets/not-an-image.png';
        Cache::flush();

        Http::fake([$remote => Http::response('this is not a png', 200)]);

        app(PaymentLogos::class)->url($remote);
        app(PaymentLogos::class)->url($remote);
        app(PaymentLogos::class)->url($remote);

        // One attempt, then the failure is remembered.
        Http::assertSentCount(1);
    }
}
