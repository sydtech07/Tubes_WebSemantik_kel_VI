<section id="ulasan-pengguna" class="cl-panel mt-4">
    <span id="ulasan" class="cl-review-anchor" aria-hidden="true"></span>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h2 class="mb-0"><i class="bi bi-chat-heart"></i> Ulasan penonton</h2>
        <span class="cl-chip">{{ $viewerReviewsCount }} rating & ulasan</span>
    </div>
    <div id="beri-ulasan" class="cl-review-compose mb-4">
        @auth
            <h3 class="h6 mb-3">{{ $myReview ? 'Perbarui rating dan ulasanmu' : 'Bagaimana menurutmu?' }}</h3>
            <form action="{{ route('reviews.store', $movie) }}" method="post" class="cl-account-form">
                @csrf
                @method('put')
                @include('partials.review-fields', ['review' => $myReview])
                <button class="btn btn-cl" type="submit"><i class="bi bi-star me-1"></i> {{ $myReview ? 'Simpan perubahan' : 'Simpan rating & ulasan' }}</button>
            </form>
        @else
            <i class="bi bi-chat-square-heart cl-empty-icon"></i>
            <h3 class="h5 mt-2">Pendapatmu berarti</h3>
            <p class="cl-sub">Masuk untuk memberi rating dan berbagi ulasan tentang film ini.</p>
            <a class="btn btn-cl" href="{{ route('login', ['next' => route('film.show', $movie, false).'#beri-ulasan']) }}">Masuk untuk memberi ulasan</a>
            <a class="btn btn-ghost ms-1" href="{{ route('register', ['next' => route('film.show', $movie, false).'#beri-ulasan']) }}">Daftar</a>
        @endauth
    </div>
    <div class="cl-rv-toolbar mb-3">
        <nav class="cl-rv-chips" aria-label="Sumber ulasan">
            @foreach (['all' => ['Semua', $viewerReviewsCount], 'cinelog' => ['CINÉLOG', $communityReviewsCount], 'tmdb' => ['TMDB', $externalReviewsCount]] as $source => [$label, $count])
                <a class="cl-rv-chip {{ $reviewSource === $source ? 'is-active' : '' }}" href="{{ route('film.show', ['movie' => $movie, 'sumber' => $source, 'urutan_ulasan' => $reviewSort]) }}#ulasan-pengguna" @if ($reviewSource === $source) aria-current="true" @endif>{{ $label }} <span>{{ $count }}</span></a>
            @endforeach
        </nav>
        <form method="get" action="{{ route('film.show', $movie) }}#ulasan-pengguna" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="sumber" value="{{ $reviewSource }}">
            <label for="review-sort" class="visually-hidden">Urutkan ulasan</label>
            <select id="review-sort" name="urutan_ulasan" class="form-select form-select-sm">
                <option value="new" @selected($reviewSort === 'new')>Terbaru</option>
                <option value="high" @selected($reviewSort === 'high')>Rating tertinggi</option>
                <option value="low" @selected($reviewSort === 'low')>Rating terendah</option>
            </select>
            <button class="btn btn-ghost btn-sm" type="submit">Urutkan</button>
        </form>
    </div>
    <p class="cl-sub small mb-4">Ulasan dari komunitas CINÉLOG dan TMDB. Rating komunitas CINÉLOG dihitung terpisah; ulasan TMDB ditampilkan dalam bahasa aslinya.</p>
    <div class="cl-viewer-reviews">
    @forelse ($viewerReviews as $item)
        @php($review = $item['review'])
        @if ($review && $item['source'] === 'tmdb')
            @include('partials.external-review-card', ['rv' => $review])
        @elseif ($review)
        <article class="cl-user-review" data-review-source="cinelog">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="cl-user-avatar">{{ mb_strtoupper(mb_substr($review->user->name, 0, 1)) }}</span>
                <div class="flex-grow-1 cl-review-author"><strong>{{ $review->user->name }}</strong><small class="d-block cl-sub">{{ $review->updated_at->locale('id')->translatedFormat('d M Y') }}@if ($review->user->isAdmin()) · Admin @endif</small></div>
                <span class="cl-review-source is-cinelog">CINÉLOG</span>
                <span class="cl-review-score"><i class="bi bi-star-fill"></i> {{ $review->rating }}<small>/10</small></span>
            </div>
            @if ($review->contains_spoiler)
                <details><summary class="cl-sub">Mengandung spoiler · buka ulasan</summary>
            @endif
            @if ($review->title)<h3 class="h6">{{ $review->title }}</h3>@endif
            @if ($review->body)<p class="cl-review-body mb-2">{{ $review->body }}</p>@endif
            @if (! $review->title && ! $review->body)<p class="cl-sub small">Memberikan rating untuk film ini.</p>@endif
            @if ($review->contains_spoiler)</details>@endif
            @if (auth()->id() === $review->user_id)
                <div class="d-flex gap-3 align-items-center mt-3">
                    <a href="#beri-ulasan" class="small">Edit ulasan</a>
                    <form action="{{ route('reviews.destroy', $review) }}" method="post" data-confirm="Hapus rating dan ulasanmu untuk film ini?">
                        @csrf @method('delete')
                        <button class="btn btn-link text-danger p-0 small" type="submit">Hapus</button>
                    </form>
                </div>
            @endif
        </article>
        @endif
    @empty
        <p class="cl-sub text-center py-3 mb-0">{{ $reviewSource === 'all' ? 'Belum ada ulasan penonton. Jadilah yang pertama!' : 'Belum ada ulasan dari sumber ini.' }}</p>
    @endforelse
    </div>
    <div class="mt-3">{{ $viewerReviews->withQueryString()->fragment('ulasan-pengguna')->links() }}</div>
</section>
