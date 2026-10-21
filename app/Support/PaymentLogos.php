<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Makes the payment logos look like one set.
 *
 * Gateways use the square icon set shared with quicklist-web (bundled in
 * public/images/payments) rather than whatever was uploaded to the admin,
 * whose files have nothing in common: Cash is a small mark floating in a 512²
 * canvas (57% filled), Midtrans fills 31% of its height, and aspect ratios run
 * from 0.85 to 5.96.
 *
 * Even the bundled set is not perfectly even — Foloosi covers 47% of its
 * canvas, Cashfree 74% — so every logo, bundled or uploaded, is redrawn once
 * onto an identical square canvas: margins trimmed, then scaled so each covers
 * the same AREA (constant geometric mean) rather than the same box, which is
 * what actually makes a wide wordmark and a square mark look equally big. The
 * result is cached on disk; the original file is used if anything goes wrong.
 */
class PaymentLogos
{
    /**
     * Gateways ship with the shared icon set — square, consistent artwork,
     * keyed by the same driver key PaymentMethods resolves labels to.
     */
    private const BUNDLED = [
        'razorpay' => 'razorpay.png',
        'stripe' => 'stripe.png',
        'paypal' => 'paypal.png',
        'paystack' => 'paystack.png',
        'flutterwave' => 'flutterwave.png',
        'xendit' => 'xendit.png',
        'midtrans' => 'midtrans.png',
        'mercadopago' => 'mercadopago.png',
        'orangepay' => 'orange_money.png',
        'payfast' => 'payfast.png',
        'cashfree' => 'cashfree.png',
        'instamojo' => 'instamojo.png',
        'paymongo' => 'payMongo.png',
        'phonepe' => 'phonepe.png',
        'foloosi' => 'foloosi.png',
        'mtnmomo' => 'mtnmom.png',
    ];

    /** Square, like the icon set, at 2.4× the on-screen chip. */
    private const CANVAS_W = 96;
    private const CANVAS_H = 96;

    /** Geometric mean of the drawn logo: √(w×h) is the same for every one. */
    private const TARGET = 76;

    /** Never let a logo touch the edge. */
    private const FILL = 0.92;

    private const DIR = 'cache/payment-logos';

    /** A pixel this transparent, or this close to white, is background. */
    private const ALPHA_CUTOFF = 100;
    private const WHITE_CUTOFF = 243;


    /**
     * The icon for a payment method: the bundled one for a known gateway,
     * otherwise whatever the admin uploaded (Cash and Wallet, typically).
     * Both go through the same normalisation.
     */
    /**
     * The icon for a payment method.
     *
     * A gateway in the shared set is served STRAIGHT from disk: those files
     * are already one consistent set of 38² squares, so redrawing them only
     * meant resampling 38 pixels up to fill a bigger canvas — which is what
     * made them look soft. Displayed at their own size they stay crisp, and
     * every row looks like it belongs to the same set.
     *
     * Anything else — Cash and Wallet, whose artwork the admin owns — goes
     * through the normaliser so it lands at the same visual weight.
     */
    public function forMethod(string $label, ?string $adminImage): ?string
    {
        $driver = PaymentMethods::driverKey($label);
        $bundled = $driver ? (self::BUNDLED[$driver] ?? null) : null;

        if ($bundled && is_file(public_path('images/payments/'.$bundled))) {
            return asset('images/payments/'.$bundled);
        }

        return $this->url($adminImage);
    }
    /** @param  string|null  $source  a local file path or the admin's URL */
    public function url(?string $source): ?string
    {
        $remote = trim((string) $source);

        if ($remote === '' || ! extension_loaded('gd')) {
            return $remote === '' ? null : $this->publicUrl($remote);
        }

        $name = 'pm-'.md5($remote).'.png';
        $path = public_path(self::DIR.'/'.$name);

        if (is_file($path)) {
            return asset(self::DIR.'/'.$name);
        }

        // A logo that cannot be normalised must not be retried on every
        // request — the page still renders, just with the original file.
        $failKey = 'cabme.logo.failed.'.md5($remote);

        if (Cache::get($failKey)) {
            return $this->publicUrl($remote);
        }

        try {
            if ($this->build($remote, $path)) {
                return asset(self::DIR.'/'.$name);
            }
        } catch (\Throwable $e) {
            Log::warning('Payment logo could not be normalised', ['url' => $remote, 'error' => $e->getMessage()]);
        }

        Cache::put($failKey, true, 600);

        return $this->publicUrl($remote);
    }

    /** A local path has to become a URL before it can be rendered. */
    private function publicUrl(string $source): string
    {
        if (! is_file($source)) {
            return $source;    // already the admin's URL
        }

        $relative = str_replace('\\', '/', substr($source, strlen(public_path())));

        return asset(ltrim($relative, '/'));
    }

    private function build(string $remote, string $path): bool
    {
        // Bundled icons live on disk; the admin's live behind a URL. Reading
        // our own files directly also avoids a request to ourselves, which
        // would deadlock the single-process dev server.
        $bytes = is_file($remote)
            ? (string) @file_get_contents($remote)
            : $this->fetch($remote);

        if ($bytes === '') {
            return false;
        }

        $source = @imagecreatefromstring($bytes);

        if (! $source) {
            return false;
        }

        $box = $this->contentBox($source);

        if (! $box) {
            imagedestroy($source);

            return false;
        }

        [$x, $y, $w, $h] = $box;

        // Equal area, not equal box: √(w×h) is the same for every logo, so a
        // 6:1 wordmark ends up wide-and-short and a square mark compact,
        // yet both read as the same size.
        $scale = self::TARGET / sqrt($w * $h);
        $drawnW = $w * $scale;
        $drawnH = $h * $scale;

        $limit = min(
            (self::CANVAS_W * self::FILL) / $drawnW,
            (self::CANVAS_H * self::FILL) / $drawnH,
            1.0,
        );

        $drawnW = (int) round($drawnW * $limit);
        $drawnH = (int) round($drawnH * $limit);

        $canvas = imagecreatetruecolor(self::CANVAS_W, self::CANVAS_H);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        imagecopyresampled(
            $canvas, $source,
            (int) round((self::CANVAS_W - $drawnW) / 2),
            (int) round((self::CANVAS_H - $drawnH) / 2),
            $x, $y,
            max(1, $drawnW), max(1, $drawnH),
            $w, $h,
        );

        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }

        imagesavealpha($canvas, true);
        $written = @imagepng($canvas, $path, 9);

        imagedestroy($canvas);
        imagedestroy($source);

        return (bool) $written;
    }

    private function fetch(string $url): string
    {
        $response = Http::timeout(6)->get($url);

        return $response->successful() ? $response->body() : '';
    }

    /**
     * The logo's real bounds inside its canvas — everything transparent or
     * near-white around it is padding the uploader happened to leave in.
     *
     * @return array{0:int,1:int,2:int,3:int}|null  [x, y, width, height]
     */
    private function contentBox(\GdImage $image): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        imagealphablending($image, false);

        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $colour = imagecolorat($image, $x, $y);

                if ((($colour >> 24) & 0x7F) > self::ALPHA_CUTOFF) {
                    continue;
                }

                if ((($colour >> 16) & 0xFF) > self::WHITE_CUTOFF
                    && (($colour >> 8) & 0xFF) > self::WHITE_CUTOFF
                    && ($colour & 0xFF) > self::WHITE_CUTOFF) {
                    continue;
                }

                if ($x < $minX) $minX = $x;
                if ($x > $maxX) $maxX = $x;
                if ($y < $minY) $minY = $y;
                if ($y > $maxY) $maxY = $y;
            }
        }

        imagealphablending($image, true);

        if ($maxX < 0) {
            return null;    // nothing but background
        }

        return [$minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1];
    }
}
