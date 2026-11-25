<?php

namespace App\Services;

use App\Models\Booking;
use App\Notifications\BookingPaid;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaymentStatus
{
    public function apply(array $data): Booking
    {
        $becamePaid = false;

        $booking = DB::transaction(function () use ($data, &$becamePaid) {
            $booking = Booking::where('midtrans_order_id', $data['order_id'] ?? '')->lockForUpdate()->firstOrFail();
            abort_unless(($data['currency'] ?? null) === 'IDR'
                && isset($data['gross_amount']) && is_numeric($data['gross_amount'])
                && (float) $data['gross_amount'] === (float) $booking->total_amount, 422, 'Nominal pembayaran tidak sesuai.');
            if (in_array($booking->status, ['paid', 'completed'], true)) {
                return $booking;
            }

            $transaction = $data['transaction_status'] ?? null;
            if ($transaction === 'settlement' || ($transaction === 'capture' && ($data['fraud_status'] ?? null) === 'accept')) {
                $booking->update(['status' => 'paid', 'paid_at' => now()]);
                $becamePaid = true;
            } elseif (in_array($transaction, ['deny', 'cancel', 'expire'], true)) {
                $booking->update(['status' => 'failed']);
            }

            return $booking;
        });

        if ($becamePaid) {
            try {
                $booking->user->notify(new BookingPaid($booking));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $booking;
    }
}
