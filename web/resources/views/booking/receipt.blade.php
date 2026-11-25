@extends('layouts.app')
@section('title', 'Receipt #'.$booking->id.' - CINÉLOG')
@section('content')
<style>
    @media print {
        nav, footer, .no-print { display: none !important; }
    }
</style>
<h1 class="cl-section-title">Receipt pembayaran</h1>
<div class="cl-panel">
    <p>Status: <strong>Lunas</strong></p>
    <p>Nomor booking: <strong>#{{ $booking->id }}</strong></p>
    <p>Nomor order: <strong>{{ $booking->midtrans_order_id }}</strong></p>
    <p>Film: <strong>{{ $movie?->title ?? $booking->movie_slug }}</strong></p>
    <p>Jadwal tayang: <strong>{{ $booking->show_start?->format('d M Y H:i') }}</strong></p>
    <p>Kursi: <strong>{{ $booking->seats->pluck('seat_code')->join(', ') }}</strong></p>
    <p>Jumlah kursi: <strong>{{ $booking->seat_count }}</strong></p>
    <p>Total dibayar: <strong>Rp {{ number_format($booking->total_amount, 0, ',', '.') }}</strong></p>
    <p>Dibayar pada: <strong>{{ $booking->paid_at?->format('d M Y H:i') }}</strong></p>
    <p>Atas nama: <strong>{{ $booking->user->name }}</strong> ({{ $booking->user->email }})</p>
</div>
<div class="no-print mt-3">
    <button type="button" class="btn btn-cl" onclick="window.print()">Cetak receipt</button>
    <a href="{{ route('bookings.show', $booking) }}" class="btn btn-ghost">Kembali ke booking</a>
</div>
@endsection
