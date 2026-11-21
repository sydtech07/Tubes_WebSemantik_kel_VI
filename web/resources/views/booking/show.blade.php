@extends('layouts.app')
@section('title', 'Booking '.$booking->id.' - CINÉLOG')
@section('content')
<h1 class="cl-section-title">Booking #{{ $booking->id }}</h1>
@if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
<div class="cl-panel"><p>Status: <strong>{{ $booking->status }}</strong></p><p>Kursi: {{ $booking->seats->pluck('seat_code')->join(', ') }}</p><p>Total: Rp {{ number_format($booking->total_amount, 0, ',', '.') }}</p></div>
@if ($booking->status === 'pending')
    <button id="pay-midtrans" class="btn btn-cl">
        Bayar sekarang
    </button>

    <script src="https://app.sandbox.midtrans.com/snap/snap.js"
            data-client-key="{{ config('services.midtrans.client_key') }}"></script>

    <script>
        document.getElementById('pay-midtrans').addEventListener('click', async function () {
            const response = await fetch('{{ route('bookings.pay', $booking) }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            if (!response.ok) {
                alert(data.message || 'Token pembayaran gagal dibuat.');
                return;
            }

            window.snap.pay(data.token, {
                onSuccess: () => window.location.reload(),
                onPending: () => window.location.reload(),
                onError: () => alert('Pembayaran gagal.')
            });
        });
    </script>
@endif
@endsection
