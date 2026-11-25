<?php

namespace App\Http\Controllers;

use App\Services\PaymentStatus;
use Illuminate\Http\Request;

class MidtransWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentStatus $payments)
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:100'],
            'status_code' => ['required', 'string', 'regex:/^[0-9]{3}$/'],
            'gross_amount' => ['required', 'string', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/'],
            'signature_key' => ['required', 'string', 'size:128'],
            'transaction_status' => ['required', 'string'],
            'fraud_status' => ['nullable', 'string'],
            'currency' => ['required', 'in:IDR'],
        ]);

        $secret = config('services.midtrans.signature_key') ?: config('services.midtrans.server_key');
        abort_if(! is_string($secret) || trim($secret) === '', 503, 'Kunci verifikasi pembayaran belum dikonfigurasi.');

        $orderId = (string) ($data['order_id'] ?? '');
        $statusCode = (string) ($data['status_code'] ?? '');
        $grossAmount = (string) ($data['gross_amount'] ?? '');
        $signature = hash(
            'sha512',
            $orderId.$statusCode.$grossAmount.$secret
        );

        abort_unless(
            hash_equals($signature, (string) ($data['signature_key'] ?? '')),
            403
        );

        $payments->apply($data);

        return response()->json(['ok' => true]);
    }
}
