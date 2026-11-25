@extends('layouts.app')
@section('title', 'Booking '.$booking->id.' - CINÉLOG')
@section('content')
<h1 class="cl-section-title">Booking #{{ $booking->id }}</h1>
@if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
<div class="cl-panel"><p>Status: <strong>{{ $booking->status }}</strong></p><p>Kursi: {{ $booking->seats->pluck('seat_code')->join(', ') }}</p><p>Total: Rp {{ number_format($booking->total_amount, 0, ',', '.') }}</p></div>
@if (in_array($booking->status, ['paid', 'completed'], true))
    <a href="{{ route('bookings.receipt', $booking) }}" class="btn btn-cl">Lihat receipt</a>
@endif
@if ($booking->status === 'pending')
    <button id="pay-midtrans" class="btn btn-cl">
        Bayar sekarang
    </button>
    <button id="check-midtrans" class="btn btn-ghost">Cek status pembayaran</button>
    <p id="payment-message" class="cl-sub mt-3" role="status" aria-live="polite">Setelah menyelesaikan pembayaran, cek status untuk memperbarui booking.</p>

    <script src="{{ config('services.midtrans.is_production') ? 'https://app.midtrans.com/snap/snap.js' : 'https://app.sandbox.midtrans.com/snap/snap.js' }}"
            data-client-key="{{ config('services.midtrans.client_key') }}"></script>

    <script>
        const payButton = document.getElementById('pay-midtrans');
        const checkButton = document.getElementById('check-midtrans');
        const message = document.getElementById('payment-message');
        let popupOpen = false;

        async function postPayment(url) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                }
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data.message || 'Permintaan pembayaran gagal. Coba lagi.');
            }
            return data;
        }

        async function checkPayment() {
            if (checkButton.disabled) return;
            checkButton.disabled = true;
            message.textContent = 'Memeriksa status ke Midtrans…';
            try {
                const data = await postPayment(@json(route('bookings.payment-status', $booking)));
                message.textContent = data.message;
                if (data.status !== 'pending') window.location.reload();
            } catch (error) {
                message.textContent = error.message;
            } finally {
                checkButton.disabled = false;
            }
        }

        function popupClosed() {
            popupOpen = false;
            payButton.disabled = false;
        }

        checkButton.addEventListener('click', checkPayment);
        payButton.addEventListener('click', async function () {
            if (popupOpen) return;
            popupOpen = true;
            payButton.disabled = true;
            try {
                if (!window.snap) throw new Error('Popup Midtrans belum termuat. Periksa koneksi dan muat ulang halaman.');
                const data = await postPayment(@json(route('bookings.pay', $booking)));
                window.snap.pay(data.token, {
                    onSuccess: function () { popupClosed(); checkPayment(); },
                    onPending: function () { popupClosed(); checkPayment(); },
                    onClose: function () { popupClosed(); checkPayment(); },
                    onError: function () {
                        popupClosed();
                        message.textContent = 'Pembayaran gagal. Periksa kembali status pembayaran.';
                    }
                });
            } catch (error) {
                popupClosed();
                message.textContent = error.message;
            }
        });
    </script>
@endif
@endsection