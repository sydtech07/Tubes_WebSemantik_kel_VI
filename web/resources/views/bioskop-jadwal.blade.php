@extends('layouts.app')

@section('title', 'Jadwal '.$cinema['name'].' - CINÉLOG')
@section('description', 'Jadwal tayang '.$cinema['name'].' dari dataset screening CINÉLOG.')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <p class="text-uppercase small fw-semibold mb-2" style="color: var(--cl-accent, #ff8a00); letter-spacing: .12em">Jadwal Bioskop</p>
            <h1 class="cl-section-title mb-2">{{ $cinema['name'] }}</h1>
            <p class="cl-sub mb-0">{{ $cinema['address'] }}, {{ $cinema['city'] }}</p>
        </div>
        <a href="{{ route('cinemas') }}" class="btn btn-ghost"><i class="bi bi-arrow-left me-1"></i> Kembali ke peta</a>
    </div>

    @if (empty($schedules))
        <div class="cl-panel text-center py-5">
            <i class="bi bi-calendar-x display-4 text-muted"></i>
            <p class="mt-3 mb-0">Belum ada jadwal dalam {{ $windowDays }} hari ke depan.</p>
        </div>
    @else
        <div class="table-responsive cl-panel p-0">
            <table class="table align-middle mb-0">
                <thead><tr><th>Film</th><th>Mulai</th><th>Studio</th><th>Format</th><th>Harga</th><th></th></tr></thead>
                <tbody>
                @foreach ($schedules as $schedule)
                    @php($slug = \Illuminate\Support\Str::after($schedule['movieUrl'], '/film/'))
                    <tr>
                        <td><a href="{{ route('film.show', $slug) }}" class="fw-semibold">{{ $schedule['title'] }}</a></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($schedule['start'])->timezone('Asia/Jakarta')->locale('id')->translatedFormat('D, d M · H:i') }} WIB</td>
                        <td>{{ $schedule['auditorium'] ?? '—' }}</td>
                        <td>{{ $schedule['format'] ?? '—' }}@if (!empty($schedule['subtitle'])) · {{ $schedule['subtitle'] }}@endif</td>
                        <td>{{ isset($schedule['price']) ? number_format((float) $schedule['price'], 0, ',', '.') . ' ' . ($schedule['currency'] ?? '') : '—' }}</td>
                        <td><a class="btn btn-cl btn-sm" href="{{ route('bookings.create', ['screening_iri' => $schedule['event'], 'movie_slug' => $slug, 'cinema_id' => $cinema['id'], 'show_start' => $schedule['start'], 'price' => (int) ($schedule['price'] ?? 0)]) }}">Pilih kursi</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p class="cl-sub small mt-3"><i class="bi bi-info-circle me-1"></i> Jadwal, studio, dan harga tiket merupakan data simulasi untuk demonstrasi CINÉLOG, bukan jadwal resmi bioskop.</p>
@endsection
