<?php

namespace App\Services;

use App\Models\Booking;
use Midtrans\Config;
use Midtrans\Snap;

class MidtransPayment
{
    public function __construct()
    {
        Config::$serverKey = config('services.midtrans.server_key');
        Config::$isProduction = config('services.midtrans.is_production');
        Config::$isSanitized = true;
        Config::$is3ds = true;
    }

    public function snapToken(Booking $booking): string
    {
        $orderId = $booking->midtrans_order_id ?: 'CINELOG-'.$booking->id.'-'.now()->timestamp;

        $booking->update([
            'midtrans_order_id' => $orderId,
        ]);

        $token = Snap::getSnapToken([
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) $booking->total_amount,
            ],
            'customer_details' => [
                'first_name' => $booking->user->name,
                'email' => $booking->user->email,
            ],
            'item_details' => [[
                'id' => 'booking-'.$booking->id,
                'price' => (int) $booking->total_amount,
                'quantity' => 1,
                'name' => 'Tiket CINÉLOG #'.$booking->id,
            ]],
        ]);

        $booking->update(['midtrans_snap_token' => $token]);

        return $token;
    }
}