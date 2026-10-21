<?php

namespace App\Http\Controllers;

use App\Services\Cabme\CabmeClient;
use App\Services\FcmService;
use App\Support\Rider;
use Illuminate\Http\Request;

/**
 * Rider ↔ driver chat.
 *
 * The messages themselves live in Firestore — the same `conversation`
 * collection the apps use — and the browser reads and writes them directly,
 * so both sides see a thread update the moment it happens.
 *
 * What the browser cannot do is reach the driver when their app is closed.
 * That needs the driver's FCM token, which is not in any payload the rider is
 * given (and should not be), so the push is sent from here instead: the panel
 * looks the token up with its own API key and sends the notification in the
 * shape the driver app already handles.
 */
class ChatController extends Controller
{
    public function __construct(
        private readonly CabmeClient $client,
        private readonly Rider $rider,
        private readonly FcmService $fcm,
    ) {
    }

    public function notify(Request $request)
    {
        $data = $request->validate([
            'driver_id' => ['required', 'integer'],
            'order_id' => ['required', 'integer'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $token = $this->driverToken((int) $data['driver_id']);

        if ($token === '') {
            // The thread is already written; a missing token only means the
            // driver will see it when they next open the chat.
            return response()->json(['ok' => true, 'notified' => false]);
        }

        $push = $this->fcm->send(
            $token,
            $this->rider->name(),
            $data['message'],
            // Mirrors the app's own chat payload so the driver app routes the
            // tap to the conversation rather than a generic screen.
            [
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                'id' => (string) $data['order_id'],
                'type' => 'chat',
                'senderId' => (string) $this->rider->id(),
                'receiverId' => (string) $data['driver_id'],
                'orderId' => (string) $data['order_id'],
                'senderName' => $this->rider->name(),
            ],
        );

        return response()->json(['ok' => true, 'notified' => (bool) ($push['ok'] ?? false)]);
    }

    private function driverToken(int $driverId): string
    {
        $result = $this->client->auth('POST', 'v1/fcm-token/', [
            'id_user' => $driverId,
            'cat_user' => 'driver',
        ]);

        if ($result->failed() || ! is_array($result->data)) {
            return '';
        }

        // The admin answers with the row, or a list holding it.
        $row = $result->data;
        $row = isset($row[0]) && is_array($row[0]) ? $row[0] : $row;

        return trim((string) ($row['fcm_id'] ?? ''));
    }
}
