<?php

namespace App\Services;

use App\Models\Booking;
use Midtrans\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class MidtransPayment
{
    public function __construct()
    {
        Config::$serverKey = config('services.midtrans.server_key');
        Config::$isProduction = config('services.midtrans.is_production');
        Config::$isSanitized = true;
        Config::$is3ds = true;
        Config::$overrideNotifUrl = config('services.midtrans.notification_url') ?: null;
    }

    public function snapToken(Booking $booking): string
    {
        DB::transaction(function () use ($booking) {
            $current = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->status === 'pending', 422, 'Booking ini tidak menunggu pembayaran.');
            if (! $current->midtrans_order_id) {
                $current->update(['midtrans_order_id' => 'CINELOG-'.$current->id]);
            }
        });

        return DB::transaction(function () use ($booking) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            abort_unless($booking->status === 'pending', 422, 'Booking ini tidak menunggu pembayaran.');
            return $this->tokenForLockedBooking($booking);
        });
    }

    private function tokenForLockedBooking(Booking $booking): string
    {
        if ($booking->midtrans_snap_token) {
            return $booking->midtrans_snap_token;
        }
        $token = $this->createToken([
            'transaction_details' => [
                'order_id' => $booking->midtrans_order_id,
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

    protected function createToken(array $payload): string
    {
        $base = config('services.midtrans.is_production') ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
        $client = Http::withBasicAuth((string) config('services.midtrans.server_key'), '')
            ->acceptJson()->connectTimeout(5)->timeout(15);
        if ($url = config('services.midtrans.notification_url')) {
            $client = $client->withHeaders(['X-Override-Notification' => $url]);
        }
        try {
            $response = $client->post($base.'/snap/v1/transactions', $payload);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['payment' => 'Koneksi Midtrans terputus. Cek status pembayaran sebelum mencoba lagi.']);
        }
        $token = $response->json('token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw ValidationException::withMessages(['payment' => 'Token pembayaran belum dapat dibuat. Cek status pembayaran atau konfigurasi Midtrans; jangan membuat booking ulang.']);
        }

        return $token;
    }

    public function sync(Booking $booking): Booking
    {
        if (! $booking->midtrans_order_id) {
            throw ValidationException::withMessages(['payment' => 'Belum ada transaksi Midtrans. Klik Bayar sekarang terlebih dahulu.']);
        }
        $key = (string) config('services.midtrans.server_key');
        if ($key === '') {
            throw ValidationException::withMessages(['payment' => 'Server Key Midtrans belum dikonfigurasi.']);
        }
        $base = config('services.midtrans.is_production') ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
        try {
            $response = Http::withBasicAuth($key, '')->acceptJson()->connectTimeout(5)->timeout(15)
                ->get($base.'/v2/'.rawurlencode($booking->midtrans_order_id).'/status');
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['payment' => 'Midtrans belum dapat dihubungi. Coba lagi sebentar.']);
        }
        if ($response->status() === 403) {
            throw ValidationException::withMessages(['payment' => 'Midtrans menolak akses cek status. Aktifkan izin Core API: Read pada Server Key di dashboard Midtrans.']);
        }
        if (! $response->successful() || ! in_array((string) $response->json('status_code'), ['200', '201', '202'], true)) {
            throw ValidationException::withMessages(['payment' => 'Status belum dapat diperiksa. Pastikan transaksi sudah dibuat dan Server Key sesuai mode Sandbox atau Production.']);
        }
        $data = $response->json();
        abort_unless(is_array($data) && ($data['order_id'] ?? null) === $booking->midtrans_order_id, 502, 'Respons transaksi tidak sesuai.');

        return app(PaymentStatus::class)->apply($data);
    }
}
