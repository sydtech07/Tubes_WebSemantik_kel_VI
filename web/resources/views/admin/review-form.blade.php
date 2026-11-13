@extends('layouts.app')
@section('title', ($review->exists ? 'Edit ulasan' : 'Tambah ulasan') . ' - Admin CINÉLOG')
@section('content')
<div class="cl-profile-edit mx-auto">
    <a href="{{ route('admin.dashboard') }}#moderasi" class="cl-sub d-inline-block mb-4"><i class="bi bi-arrow-left"></i> Kembali ke dashboard</a>
    <section class="cl-panel">
        <span class="cl-kicker mb-3">Moderasi</span>
        <h1 class="h4">{{ $review->exists ? 'Edit ulasan' : 'Tambah ulasan admin' }}</h1>
        @if ($review->exists)<p class="cl-sub">{{ $review->movie->title }} · oleh {{ $review->user->name }}</p>@else<p class="cl-sub">Ulasan baru akan menggunakan akun adminmu.</p>@endif
        <form action="{{ $review->exists ? route('admin.reviews.update', $review) : route('admin.reviews.store') }}" method="post" class="cl-account-form">
            @csrf
            @if ($review->exists) @method('patch') @endif
            @unless ($review->exists)
                <label for="movie-id" class="form-label">Film</label>
                <select id="movie-id" name="movie_id" class="form-select mb-4" required>
                    <option value="">Pilih film</option>
                    @foreach ($movies as $movie)<option value="{{ $movie->id }}" @selected((int) old('movie_id') === $movie->id)>{{ $movie->title }}</option>@endforeach
                </select>
            @endunless
            @include('partials.review-fields')
            <button class="btn btn-cl" type="submit">Simpan ulasan</button>
            <a class="btn btn-ghost ms-2" href="{{ route('admin.dashboard') }}#moderasi">Batal</a>
        </form>
    </section>
</div>
@endsection
