{{-- Kartu film untuk hasil pencarian dan halaman orang. Masukan: $m (title, slug, date, poster, vote), $r (rating CINÉLOG, boleh null), $note (teks kecil, opsional) --}}
<div class="col cl-col" style="--i: {{ $i ?? 0 }}">
    <a href="{{ route('film.show', $m['slug']) }}" class="cl-card">
        <div class="cl-poster-wrap">
            <div class="cl-poster">
                @if ($m['poster'])
                    {{-- w342 cukup untuk kartu; file RDF menyimpan w500 yang lebih besar --}}
                    <img src="{{ str_replace('/w500/', '/w342/', $m['poster']) }}" alt="Poster {{ $m['title'] }}" loading="lazy" decoding="async">
                @else
                    <div class="cl-poster-empty">tanpa poster</div>
                @endif
                <div class="cl-overlay"><span><i class="bi bi-play-circle-fill"></i> Lihat detail</span></div>
            </div>
            @if ($m['vote'])
                <div class="cl-ring" title="Skor TMDB" style="--p: {{ round($m['vote'] * 10) }}; --c: {{ $m['vote'] >= 7 ? '#2ecc71' : ($m['vote'] >= 5 ? '#ffc83d' : '#ff4d4d') }}">
                    <span>{{ number_format($m['vote'], 1) }}</span>
                </div>
            @endif
        </div>
        <div class="cl-card-body">
            <div class="cl-title">{{ $m['title'] }}</div>
            <div class="cl-meta">{{ \Illuminate\Support\Carbon::parse($m['date'])->locale('id')->translatedFormat('d M Y') }}</div>
            @if (! empty($note))
                <div class="cl-meta">{{ $note }}</div>
            @endif
            <div class="cl-meta">
                @if ($r && $r->reviews_count > 0)
                    <i class="bi bi-people-fill"></i> {{ number_format($r->avg_rating, 1) }} ({{ $r->reviews_count }} ulasan)
                @else
                    belum ada ulasan
                @endif
            </div>
        </div>
    </a>
</div>