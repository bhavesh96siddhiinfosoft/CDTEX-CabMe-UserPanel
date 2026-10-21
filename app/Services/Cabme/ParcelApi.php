<?php

namespace App\Services\Cabme;

use App\Support\ApiResult;
use App\Support\Rider;

/** Parcel delivery lifecycle. */
class ParcelApi
{
    public function __construct(
        private readonly CabmeClient $client,
        private readonly Rider $rider,
    ) {
    }

    /**
     * The admin requires id_payment and transaction_id up front, so a parcel
     * is paid for (or marked cash) before the order exists.
     */
    public function book(array $order, array $images = []): ApiResult
    {
        $fields = [
            'id_user' => $this->rider->id(),
            'lat_source' => $order['pickup_lat'],
            'lng_source' => $order['pickup_lng'],
            'lat_destination' => $order['drop_lat'],
            'lng_destination' => $order['drop_lng'],
            'source_adrs' => $order['pickup_address'],
            'destination_adrs' => $order['drop_address'],
            'distance' => $order['distance'],
            'distance_unit' => $order['distance_unit'],
            'id_payment' => $order['payment_method_id'],
            'sender_name' => $order['sender_name'],
            'sender_phone' => $order['sender_phone'],
            'receiver_name' => $order['receiver_name'],
            'receiver_phone' => $order['receiver_phone'],
            'parcel_type' => $order['category_id'],
            'parcel_weight' => $order['weight'],
            'parcel_dimension' => $order['dimension'],
            'note' => $order['note'] ?? null,
            'parcel_date' => $order['pickup_date'],
            'parcel_time' => $order['pickup_time'],
            'receive_date' => $order['receive_date'],
            'receive_time' => $order['receive_time'],
            'amount' => $order['amount'],
            'transaction_id' => $order['transaction_id'],
        ];

        // The admin records the coupon on the order (discount_type json); the
        // discounted amount above is what actually gets charged and stored.
        if (! empty($order['coupon_id'])) {
            $fields['discount_id'] = $order['coupon_id'];
        }

        return $images
            ? $this->client->upload('v1/parcel-register', $fields, $images)
            : $this->client->auth('POST', 'v1/parcel-register', $fields);
    }

    public function mine(): ApiResult
    {
        return $this->client->auth('POST', 'v1/get-user-parcel-orders', [
            'id_user' => $this->rider->id(),
        ]);
    }

    public function show(int $parcelId): ApiResult
    {
        return $this->client->auth('POST', 'v1/get-parcel-detail', ['id_parcel' => $parcelId]);
    }

    public function cancel(int $parcelId, ?string $reason = null): ApiResult
    {
        return $this->client->auth('POST', 'v1/parcel-canceled', [
            'id_parcel' => $parcelId,
            'id_user' => $this->rider->id(),
            'reason' => $reason,
        ]);
    }
}
