<?php

namespace App\Support;

/**
 * A short fingerprint of the current asset build.
 *
 * Rendered onto <body data-build="…"> so a stale page in a browser can be told
 * apart from the code the server is actually serving — which cost several
 * rounds of "it's still broken" against a fix that was already live.
 */
class BuildStamp
{
    public static function current(): string
    {
        $manifest = public_path('build/manifest.json');

        return is_file($manifest)
            ? substr(md5_file($manifest), 0, 8)
            : 'dev';
    }
}
