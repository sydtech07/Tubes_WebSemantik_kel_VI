@php
    $plain = trim(preg_replace('/\s+/u', ' ', preg_replace(['/\[([^\]]*)\]\([^)]*\)/u', '/[*_#>`~]+/u'], ['$1', ''], $rv->content)));
@endphp
<article class="cl-user-review" data-review-source="tmdb">
    <div class="d-flex align-items-center gap-3 mb-3">
        <span class="cl-user-avatar cl-tmdb-avatar">{{ mb_strtoupper(mb_substr($rv->author, 0, 1)) }}</span>
        <div class="flex-grow-1 cl-review-author"><strong>{{ $rv->author }}</strong><small class="d-block cl-sub">{{ $rv->posted_at?->locale('id')->translatedFormat('d M Y') ?? 'Tanggal tidak tersedia' }}</small></div>
        <span class="cl-review-source is-tmdb">TMDB</span>
        @if ($rv->rating !== null)
            <span class="cl-review-score"><i class="bi bi-star-fill"></i> {{ number_format($rv->rating, 1) }}<small>/10</small></span>
        @endif
    </div>
    <details class="cl-review-expand">
        <summary><span class="cl-review-preview">{{ \Illuminate\Support\Str::limit($plain, 400) }}</span><span class="cl-review-read-more">Baca ulasan lengkap <i class="bi bi-chevron-down"></i></span></summary>
        <div class="cl-rv-md mt-3">{!! $rv->content_html !!}</div>
    </details>
    @if ($rv->source_url && preg_match('~^https://(?:www\.)?themoviedb\.org/(?:review|movie)/~i', $rv->source_url))
        <a class="small d-inline-block mt-3" href="{{ $rv->source_url }}" target="_blank" rel="noopener noreferrer">Lihat di TMDB <i class="bi bi-box-arrow-up-right"></i></a>
    @endif
</article>
