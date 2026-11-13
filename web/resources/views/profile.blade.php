@extends('layouts.app')
@section('title', 'Profil saya - CINÉLOG')
@section('content')
<header class="cl-profile-header mb-4">
    <span class="cl-user-avatar cl-user-avatar-lg">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
    <div><span class="cl-sub small">RUANG FILMMU</span><h1 class="h3 mt-1 mb-1">{{ $user->name }}</h1><p class="cl-sub mb-0">Bergabung {{ $user->created_at->locale('id')->translatedFormat('F Y') }}</p></div>
    <a href="#edit-profil" class="btn btn-ghost ms-sm-auto">Edit profil</a>
</header>
<div class="cl-stats mb-4">
    <div class="cl-panel"><i class="bi bi-star"></i><strong>{{ $reviewCount }}</strong><span>Film diberi rating</span></div>
    <div class="cl-panel"><i class="bi bi-bookmark"></i><strong>{{ $watchlistCount }}</strong><span>Dalam watchlist</span></div>
    <div class="cl-panel"><i class="bi bi-heart"></i><strong>{{ $averageRating ? number_format($averageRating, 1) : '—' }}</strong><span>Rata-rata ratingmu / 10</span></div>
</div>
<nav class="cl-chips mb-4" aria-label="Bagian profil">
    <a href="#rating-saya" class="cl-chip"><i class="bi bi-chat-quote"></i> Rating & ulasan</a>
    <a href="#watchlist" class="cl-chip"><i class="bi bi-bookmark"></i> Watchlist</a>
</nav>
<section id="watchlist" class="mb-5">
    <h2 class="cl-section-title mb-4">Watchlist saya</h2>
    <div class="row g-3">
        @forelse ($watchlist as $saved)
            <div class="col-6 col-md-3 col-lg-2">
                <a class="cl-card d-block h-auto" href="{{ route('film.show', $saved) }}">
                    <div class="cl-poster">@if ($saved->poster_url)<img src="{{ $saved->poster_url }}" alt="Poster {{ $saved->title }}" loading="lazy">@else<div class="cl-saved-placeholder"><i class="bi bi-film"></i></div>@endif</div>
                    <div class="p-3"><h3 class="h6 mb-1">{{ $saved->title }}</h3><small class="cl-sub">{{ $saved->release_date->format('Y') }}</small></div>
                </a>
                <form class="mt-2" action="{{ route('watchlist.destroy', $saved) }}" method="post">
                    @csrf @method('delete')
                    <button class="btn btn-ghost btn-sm w-100" type="submit"><i class="bi bi-bookmark-dash"></i> Hapus</button>
                </form>
            </div>
        @empty
            <div class="col-12"><div class="cl-panel text-center py-5"><i class="bi bi-bookmark-plus cl-empty-icon"></i><h3 class="h5 mt-3">Daftar tontonanmu dimulai di sini</h3><p class="cl-sub">Simpan film dari halaman detail agar mudah ditemukan lagi.</p><a class="btn btn-cl" href="{{ route('home') }}">Jelajahi film</a></div></div>
        @endforelse
    </div>
    <div class="mt-3">{{ $watchlist->withQueryString()->fragment('watchlist')->links() }}</div>
</section>
<section id="rating-saya" class="mb-5">
    <h2 class="cl-section-title mb-4">Rating & ulasan saya</h2>
    @forelse ($reviews as $review)
        <article class="cl-panel mb-3">
            <div class="d-flex justify-content-between gap-3 mb-2">
                <div><a class="h5 text-decoration-none" href="{{ route('film.show', $review->movie) }}">{{ $review->movie->title }}</a><small class="d-block cl-sub mt-1">{{ $review->updated_at->format('d M Y') }} @if ($review->contains_spoiler) · Mengandung spoiler @endif</small></div>
                <span class="cl-review-score"><i class="bi bi-star-fill"></i> {{ $review->rating }}<small>/10</small></span>
            </div>
            @if ($review->title)<h3 class="h6 mt-3">{{ $review->title }}</h3>@endif
            @if ($review->body)<p class="cl-review-body">{{ $review->body }}</p>@endif
            <div class="d-flex gap-3 mt-3 align-items-center">
                <a class="small" href="{{ route('film.show', $review->movie) }}#beri-ulasan">Edit rating & ulasan</a>
                <form action="{{ route('reviews.destroy', $review) }}" method="post" data-confirm="Hapus rating dan ulasan ini?">@csrf @method('delete')<input type="hidden" name="return_to" value="profile"><button type="submit" class="btn btn-link p-0 text-danger small">Hapus</button></form>
            </div>
        </article>
    @empty
        <div class="cl-panel text-center py-5"><i class="bi bi-star cl-empty-icon"></i><h3 class="h5 mt-3">Film apa yang terakhir kamu tonton?</h3><p class="cl-sub mb-0">Beri rating dari halaman film. Semua pendapatmu akan terkumpul di sini.</p></div>
    @endforelse
    {{ $reviews->withQueryString()->fragment('rating-saya')->links() }}
</section>
<section id="edit-profil" class="cl-panel cl-profile-edit">
    <h2><i class="bi bi-person-gear"></i> Edit profil</h2>
    <form class="cl-account-form" action="{{ route('profile.update') }}" method="post">
        @csrf @method('patch')
        <label class="form-label" for="profile-name">Nama</label>
        <input class="form-control mb-3" id="profile-name" name="name" value="{{ old('name', $user->name) }}" maxlength="100" autocomplete="name" required>
        <label class="form-label" for="profile-email">Email</label>
        <input class="form-control mb-4" id="profile-email" type="email" name="email" value="{{ old('email', $user->email) }}" maxlength="190" autocomplete="email" required>
        <button class="btn btn-cl" type="submit">Simpan profil</button>
    </form>
</section>
@endsection
