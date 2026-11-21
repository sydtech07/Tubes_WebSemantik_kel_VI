<section id="ulasan-tmdb" class="cl-panel mt-4">
    <span id="ulasan" class="cl-review-anchor" aria-hidden="true"></span>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h2 class="mb-0"><i class="bi bi-chat-quote"></i> Ulasan dari TMDB</h2>
        <span class="cl-chip">{{ $viewerReviewsCount }} ulasan</span>
    </div>
    @if ($viewerReviewsCount > 0)
        <form method="get" action="{{ route('film.show', $movie) }}#ulasan-tmdb" class="d-flex gap-2 align-items-center justify-content-end mb-3">
            <label for="review-sort" class="visually-hidden">Urutkan ulasan</label>
            <select id="review-sort" name="urutan_ulasan" class="form-select form-select-sm">
                <option value="new" @selected($reviewSort === 'new')>Terbaru</option>
                <option value="high" @selected($reviewSort === 'high')>Rating tertinggi</option>
                <option value="low" @selected($reviewSort === 'low')>Rating terendah</option>
            </select>
            <button class="btn btn-ghost btn-sm" type="submit">Urutkan</button>
        </form>
        <p class="cl-sub small mb-4">Ulasan dan rating film berasal dari TMDB, bukan rating pengguna CINÉLOG.</p>
    @endif
    <div class="cl-viewer-reviews">
        @forelse ($viewerReviews as $item)
            @include('partials.external-review-card', ['rv' => $item['review']])
        @empty
            <p class="cl-sub text-center py-3 mb-0">Belum ada ulasan TMDB untuk film ini.</p>
        @endforelse
    </div>
    <div class="mt-3">{{ $viewerReviews->withQueryString()->fragment('ulasan-tmdb')->links() }}</div>
</section>
