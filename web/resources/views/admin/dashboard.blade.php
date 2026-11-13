@extends('layouts.app')
@section('title', 'Dashboard admin - CINÉLOG')
@section('content')
<header class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><span class="cl-kicker mb-2">Admin</span><h1 class="h3 mb-1">Di balik layar CINÉLOG</h1><p class="cl-sub mb-0">Pantau komunitas dan kelola ulasan penonton.</p></div>
    <a href="#moderasi" class="btn btn-ghost"><i class="bi bi-chat-square-text me-1"></i> Kelola ulasan</a>
</header>
<div class="cl-stats cl-stats-four mb-4">
    <div class="cl-panel"><i class="bi bi-people"></i><strong>{{ number_format($userCount) }}</strong><span>Total pengguna</span></div>
    <div class="cl-panel"><i class="bi bi-activity"></i><strong>{{ number_format($activeCount) }}</strong><span>Aktif dalam 15 menit</span></div>
    <div class="cl-panel"><i class="bi bi-chat-quote"></i><strong>{{ number_format($reviewCount) }}</strong><span>Rating & ulasan</span></div>
    <div class="cl-panel"><i class="bi bi-bookmark"></i><strong>{{ number_format($watchlistCount) }}</strong><span>Film disimpan</span></div>
</div>
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <section class="cl-panel h-100">
            <h2>Aktivitas komunitas</h2><p class="cl-sub small">Pendaftaran dan rating baru selama 14 hari terakhir.</p>
            <div class="cl-chart-legend mb-3"><span><i class="cl-dot cl-dot-users"></i> Pengguna baru</span><span><i class="cl-dot cl-dot-reviews"></i> Rating baru</span></div>
            <div class="cl-chart-scroll">
                <div class="cl-activity-chart" role="img" aria-label="Grafik aktivitas komunitas 14 hari terakhir. Rincian angka tersedia di bawah grafik.">
                    @foreach ($activity as $day)
                        <div class="cl-chart-day"><div class="cl-chart-bars"><span class="cl-bar-users" style="height: {{ $day['users'] / $chartMax * 100 }}%" title="{{ $day['users'] }} pengguna"></span><span class="cl-bar-reviews" style="height: {{ $day['reviews'] / $chartMax * 100 }}%" title="{{ $day['reviews'] }} rating"></span></div><small>{{ $day['label'] }}</small></div>
                    @endforeach
                </div>
            </div>
            <details class="small mt-3"><summary class="cl-sub">Lihat angka harian</summary><div class="table-responsive mt-2"><table class="table table-sm"><thead><tr><th>Tanggal</th><th>Pengguna baru</th><th>Rating baru</th></tr></thead><tbody>@foreach ($activity as $day)<tr><td>{{ $day['label'] }}</td><td>{{ $day['users'] }}</td><td>{{ $day['reviews'] }}</td></tr>@endforeach</tbody></table></div></details>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="cl-panel h-100"><h2>Distribusi rating</h2><p class="cl-sub small">Skor yang diberikan seluruh penonton.</p>
            @for ($rating = 10; $rating >= 1; $rating--)
                <div class="cl-rating-distribution"><span>{{ $rating }} <i class="bi bi-star-fill"></i></span><meter min="0" max="{{ $ratingMax }}" value="{{ $distribution[$rating] ?? 0 }}" aria-label="Rating {{ $rating }}: {{ $distribution[$rating] ?? 0 }} pengguna"></meter><small>{{ $distribution[$rating] ?? 0 }}</small></div>
            @endfor
        </section>
    </div>
</div>
<section id="pengguna" class="cl-panel mb-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3"><h2 class="mb-0">Pengguna</h2>
        <form method="get" action="{{ route('admin.dashboard') }}#pengguna" class="d-flex gap-2 cl-account-form"><label for="user-search" class="visually-hidden">Cari pengguna</label><input id="user-search" class="form-control" name="q" value="{{ $q }}" placeholder="Cari nama atau email"><button class="btn btn-ghost" type="submit">Cari</button></form>
    </div>
    <p class="cl-sub small">Status aktif mengikuti aktivitas akun dalam 15 menit terakhir. Muat ulang untuk memperbarui data.</p>
    <div class="table-responsive"><table class="table align-middle cl-admin-table">
        <thead><tr><th>Pengguna</th><th>Peran</th><th>Rating</th><th>Aktivitas terakhir</th><th>Status</th></tr></thead>
        <tbody>@forelse ($users as $user)<tr><td><strong>{{ $user->name }}</strong><small class="d-block cl-sub">{{ $user->email }}</small></td><td>{{ $user->isAdmin() ? 'Admin' : 'Pengguna' }}</td><td>{{ $user->reviews_count }}</td><td>{{ $user->last_seen_at?->locale('id')->diffForHumans() ?? 'Belum ada aktivitas' }}</td><td><span class="cl-status {{ $user->last_seen_at?->gte(now()->subMinutes(15)) ? 'is-active' : '' }}">{{ $user->last_seen_at?->gte(now()->subMinutes(15)) ? 'Aktif' : 'Offline' }}</span></td></tr>@empty<tr><td colspan="5" class="text-center cl-sub py-4">Pengguna tidak ditemukan.</td></tr>@endforelse</tbody>
    </table></div>
    {{ $users->fragment('pengguna')->links() }}
</section>
<section id="moderasi" class="cl-panel">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h2 class="mb-1">Moderasi ulasan</h2><p class="cl-sub small mb-0">Kelola rating dan komentar dari komunitas.</p></div><a href="{{ route('admin.reviews.create') }}" class="btn btn-cl"><i class="bi bi-plus-lg"></i> Tambah ulasan</a></div>
    @forelse ($reviews as $review)
        <article class="cl-user-review">
            <div class="d-flex justify-content-between gap-3"><div><a class="h6" href="{{ route('film.show', $review->movie) }}#ulasan-pengguna">{{ $review->movie->title }}</a><small class="d-block cl-sub mt-1">{{ $review->user->name }} · {{ $review->updated_at->format('d M Y H:i') }}@if ($review->contains_spoiler) · Spoiler @endif</small></div><span class="cl-review-score"><i class="bi bi-star-fill"></i> {{ $review->rating }}<small>/10</small></span></div>
            @if ($review->title)<h3 class="h6 mt-3">{{ $review->title }}</h3>@endif
            @if ($review->body)<p class="cl-review-body mt-2">{{ $review->body }}</p>@endif
            <div class="d-flex gap-2 mt-3"><a class="btn btn-ghost btn-sm" href="{{ route('admin.reviews.edit', $review) }}">Edit</a><form method="post" action="{{ route('admin.reviews.destroy', $review) }}" data-confirm="Hapus rating dan ulasan ini dari CINÉLOG?">@csrf @method('delete')<button class="btn btn-outline-danger btn-sm rounded-pill" type="submit">Hapus</button></form></div>
        </article>
    @empty
        <p class="text-center cl-sub py-4">Belum ada ulasan untuk dimoderasi.</p>
    @endforelse
    <div class="mt-3">{{ $reviews->fragment('moderasi')->links() }}</div>
</section>
@endsection
