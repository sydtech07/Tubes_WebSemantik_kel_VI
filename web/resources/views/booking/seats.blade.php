@extends('layouts.app')
@section('title', 'Pilih Kursi - CINÉLOG')
@section('content')
<h1 class="cl-section-title">Pilih Kursi</h1>
<p class="cl-sub">Screening {{ $screening['movie_slug'] }} · {{ $screening['show_start'] }}</p>
<form method="POST" action="{{ route('bookings.store') }}">
    @csrf
    @foreach ($screening as $key => $value)
        @if ($key !== 'seats') <input type="hidden" name="{{ $key }}" value="{{ $value }}"> @endif
    @endforeach
    <div class="cl-panel mb-3"><div class="text-center mb-3">LAYAR</div>
        <div class="row row-cols-4 g-2">
            @foreach (range('A', 'H') as $row)
                @foreach (range(1, 12) as $number)
                    @php($seat = $row . str_pad($number, 2, '0', STR_PAD_LEFT))
                    <label class="btn btn-ghost"><input type="checkbox" name="seats[]" value="{{ $seat }}" class="me-1">{{ $seat }}</label>
                @endforeach
            @endforeach
        </div>
    </div>
    @error('seats')<div class="alert alert-danger">{{ $message }}</div>@enderror
    <button class="btn btn-cl" type="submit">Tahan Kursi</button>
</form>
@endsection
