<?php

namespace App\Services\Cabme;

use App\Support\ApiResult;
use App\Support\Rider;

/** Vehicle rental lifecycle (hourly / daily packages). */
class RentalApi
{
    public function __construct(
        private readonly CabmeClient $client,
        private readonly Rider $rider,
    ) {
    }

    public function book(array $order): ApiResult
    {
        $fields = [
            'id_user' => $this->rider->id(),
            'lat_source' => $order['pickup_lat'],
            'lng_source' => $order['pickup_lng'],
            'depart_name' => $order['pickup_address'],
            'id_payment' => $order['payment_method_id'],
            'id_rental_package' => $order['package_id'],
            'id_vehicle_type' => $order['vehicle_type_id'],
            'amount' => $order['amount'],
            'transaction_id' => $order['transaction_id'],
            'start_date' => $order['start_date'],
            'start_time' => $order['start_time'],
            'end_date' => $order['end_date'],
            'end_time' => $order['end_time'],
        ];

        // The admin records the coupon on the order (discount_type json); the
        // discounted amount above is what actually gets charged and stored.
        if (! empty($order['coupon_id'])) {
            $fields['discount_id'] = $order['coupon_id'];
        }

        return $this->client->auth('POST', 'v1/rental-register', $fields);
    }

    /**
     * Settle and close the rental. As in the app, the CUSTOMER completes a
     * rental by paying — after the driver has filed the final km, which is
     * what fixes the amount (base fare plus any extra distance and time).
     */
    public function complete(array $order, int $paymentMethodId, string $transactionId): ApiResult
    {
        return $this->client->auth('POST', 'v1/rental-complete', [
            'id_rental' => $order['id'],
            'id_user' => $this->rider->id(),
            'id_driver' => $order['id_conducteur'],
            'id_payment' => $paymentMethodId,
            'transaction_id' => $transactionId,
        ]);
    }

    /**
     * Record the rider's choice without settling anything — used when they
     * pick cash, which the driver collects in person.
     */
    public function changePaymentMethod(int $rentalId, int $paymentMethodId): ApiResult
    {
        return $this->client->auth('POST', 'v1/change-booking-payment-method/', [
            'booking_type' => 'rental',
            'booking_id' => $rentalId,
            'id_payment' => $paymentMethodId,
        ]);
    }

    public function show(int $rentalId): ApiResult
    {
        return $this->client->auth('POST', 'v1/get-rental-booking-details/', [
            'id_rental' => $rentalId,
        ]);
    }

    public function cancel(int $rentalId, ?string $reason = null): ApiResult
    {
        return $this->client->auth('POST', 'v1/rental-canceled', [
            'id_rental' => $rentalId,
            'id_user' => $this->rider->id(),
            'reason' => $reason,
        ]);
    }
}
