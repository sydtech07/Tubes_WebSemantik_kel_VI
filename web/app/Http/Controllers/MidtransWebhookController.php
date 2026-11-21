<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MidtransWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->all();

        $orderId = (string) ($data['order_id'] ?? '');
        $statusCode = (string) ($data['status_code'] ?? '');
        $grossAmount = (string) ($data['gross_amount'] ?? '');
        $signature = hash(
            'sha512',
            $orderId.$statusCode.$grossAmount.config('services.midtrans.server_key')
        );

        abort_unless(
            hash_equals($signature, (string) ($data['signature_key'] ?? '')),
            403
        );

        $booking = Booking::where('midtrans_order_id', $orderId)->firstOrFail();
        $transaction = $data['transaction_status'] ?? null;
        $fraud = $data['fraud_status'] ?? null;

        if (
            $transaction === 'settlement' ||
            ($transaction === 'capture' && $fraud === 'accept')
        ) {
            $booking->update([
                'status' => 'paid',
                'paid_at' => Carbon::now(),
            ]);
        } elseif (in_array($transaction, ['deny', 'cancel', 'expire'], true)) {
            $booking->update(['status' => 'failed']);
        }

        return response()->json(['ok' => true]);
    }
}