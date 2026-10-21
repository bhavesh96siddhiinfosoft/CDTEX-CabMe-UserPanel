<?php

namespace App\Http\Controllers;

use App\Services\Cabme\CabmeClient;
use App\Services\FcmService;
use App\Support\Rider;
use Illuminate\Http\Request;

/**
 * Web push registration.
 *
 * The browser obtains an FCM token (see resources/js/push.js) and hands it
 * here; it is stored as the rider's fcm_id through the admin's own
 * v1/update-fcm/ endpoint — the SAME slot the mobile apps use — so every
 * ride/parcel/rental notification the admin backend already sends reaches
 * this browser with no extra sending code.
 */
class PushController extends Controller
{
    public function __construct(
        private readonly CabmeClient $client,
        private readonly Rider $rider,
        private readonly FcmService $fcm,
    ) {
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:512'],
            // True for routine token refreshes on page load — no fanfare.
            'silent' => ['nullable', 'boolean'],
        ]);

        $result = $this->client->auth('POST', 'v1/update-fcm/', [
            'user_id' => $this->rider->id(),
            'fcm_id' => $data['token'],
            'device_id' => 'web',
            'user_cat' => 'user_app',
        ]);

        if ($result->failed()) {
            return response()->json(['message' => $result->messageOr(__('Could not enable notifications.'))], 422);
        }

        // A visible hello doubles as an end-to-end check of the credentials —
        // but only when the rider explicitly enabled alerts, never on the
        // silent refresh every page load performs.
        if (! ($data['silent'] ?? false)) {
            $this->fcm->send($data['token'], __('Notifications enabled'), __('You will get ride updates here.'));
        }

        return response()->json(['ok' => true]);
    }
}
