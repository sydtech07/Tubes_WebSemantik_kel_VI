{{-- Panel ulasan TMDB. Masukan: $externalReviews (koleksi ExternalReview, terbaru dulu), $externalReviewsCount (total di DB).
     Urut, filter, dan "tampilkan lebih banyak" dikerjakan di browser (JS di bawah); tanpa JS semua kartu tetap tampil. --}}
@php
    $tone = fn ($r) => $r === null ? 'none' : ($r >= 7 ? 'pos' : ($r >= 5 ? 'neu' : 'neg'));
    $rated = $externalReviews->whereNotNull('rating');
    $avg   = $rated->count() ? $rated->avg('rating') : null;
    $dist  = ['pos' => 0, 'neu' => 0, 'neg' => 0];
    foreach ($rated as $rv) {
        $dist[$tone($rv->rating)]++;
    }
    $ratedN   = max(1, $rated->count());
    $avgColor = $avg === null ? '#98a2b8' : ($avg >= 7 ? '#2ecc71' : ($avg >= 5 ? '#ffc83d' : '#ff4d4d'));
    $labels   = ['pos' => 'Positif', 'neu' => 'Netral', 'neg' => 'Negatif'];
@endphp

<div class="cl-panel mt-4" id="ulasan">
    <h2>
        <i class="bi bi-chat-quote"></i> Ulasan dari TMDB
        @if ($externalReviewsCount) <small class="cl-sub">({{ $externalReviewsCount }})</small> @endif
    </h2>

    @if ($externalReviews->isEmpty())
        <p class="cl-sub mb-0">Belum ada ulasan dari TMDB untuk film ini.</p>
    @else
        {{-- ===== Ringkasan: rata-rata rating + sebaran positif/netral/negatif ===== --}}
        @if ($avg !== null)
            <div class="cl-rv-summary">
                <div class="cl-rv-avg">
                    <div class="cl-rv-avg-num" style="color: {{ $avgColor }}">{{ number_format($avg, 1) }}</div>
                    <div class="cl-sub small">rata-rata dari<br>{{ $rated->count() }} ulasan berating</div>
                </div>
                <div class="cl-rv-dist">
                    @foreach ($labels as $key => $label)
                        <div class="cl-rv-dist-row">
                            <span class="cl-rv-dist-label">{{ $label }}</span>
                            <span class="cl-rv-bar"><span class="cl-rv-bar-fill cl-tone-{{ $key }}" style="width: {{ round($dist[$key] / $ratedN * 100) }}%"></span></span>
                            <span class="cl-rv-dist-n">{{ $dist[$key] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ===== Filter + urutan ===== --}}
        <div class="cl-rv-toolbar">
            <div class="cl-rv-chips" role="group" aria-label="Filter ulasan">
                <button type="button" class="cl-rv-chip is-active" data-tone="all" aria-pressed="true">Semua <span>{{ $externalReviews->count() }}</span></button>
                @foreach ($labels as $key => $label)
                    @if ($dist[$key] > 0)
                        <button type="button" class="cl-rv-chip" data-tone="{{ $key }}" aria-pressed="false">{{ $label }} <span>{{ $dist[$key] }}</span></button>
                    @endif
                @endforeach
            </div>
            <label class="cl-rv-sort">
                <span class="visually-hidden">Urutkan ulasan</span>
                <select class="form-select form-select-sm" data-sort>
                    <option value="new">Terbaru</option>
                    <option value="high">Rating tertinggi</option>
                    <option value="low">Rating terendah</option>
                </select>
            </label>
        </div>

        {{-- ===== Kartu ulasan ===== --}}
        <div class="cl-rv-grid">
            @foreach ($externalReviews as $rv)
                @php
                    $t     = $tone($rv->rating);
                    // pratinjau: markdown dibuang jadi teks polos; isi lengkap (markdown dirender aman) ada di <template>
                    $plain = trim(preg_replace('/\s+/u', ' ', preg_replace(['/\[([^\]]*)\]\([^)]*\)/u', '/[*_#>`~]+/u'], ['$1', ''], $rv->content)));
                    $tgl   = $rv->posted_at?->locale('id')->translatedFormat('d M Y');
                @endphp
                <article class="cl-rv cl-tone-{{ $t }}"
                         data-tone="{{ $t }}"
                         data-rating="{{ $rv->rating ?? '' }}"
                         data-ts="{{ $rv->posted_at?->timestamp ?? 0 }}"
                         data-author="{{ $rv->author }}"
                         data-date="{{ $tgl }}"
                         data-url="{{ $rv->source_url }}">
                    <header class="cl-rv-head">
                        <span class="cl-rv-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($rv->author, 0, 1)) }}</span>
                        <span class="cl-rv-who">
                            <strong>{{ $rv->author }}</strong>
                            <small class="cl-sub">{{ $tgl }}</small>
                        </span>
                        @if ($rv->rating !== null)
                            <span class="cl-rv-pill cl-tone-{{ $t }}" title="Rating dari penulis ulasan"><i class="bi bi-star-fill"></i> {{ number_format($rv->rating, 1) }}</span>
                        @endif
                    </header>
                    <p class="cl-rv-text">{{ \Illuminate\Support\Str::limit($plain, 700) }}</p>
                    <button type="button" class="cl-rv-open" data-bs-toggle="modal" data-bs-target="#clRvModal">Baca selengkapnya <i class="bi bi-arrow-right-short"></i></button>
                    <template>{!! $rv->content_html !!}</template>
                </article>
            @endforeach
        </div>

        <p class="cl-rv-empty cl-sub text-center my-3" hidden>Tidak ada ulasan pada kategori ini.</p>

        <div class="text-center mt-3">
            <button type="button" class="btn btn-ghost btn-sm cl-rv-more" hidden>Tampilkan lebih banyak (<span class="cl-rv-more-n">0</span>)</button>
        </div>

        <p class="cl-sub small mb-0 mt-3">
            Ulasan ditulis pengguna TMDB dan ditampilkan dalam bahasa aslinya (umumnya Inggris).
            @if ($externalReviewsCount > $externalReviews->count())
                Menampilkan {{ $externalReviews->count() }} ulasan terbaru dari {{ $externalReviewsCount }}.
            @endif
        </p>

        {{-- ===== Jendela baca ulasan penuh ===== --}}
        <div class="modal fade" id="clRvModal" tabindex="-1" aria-labelledby="clRvModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
                <div class="modal-content cl-rv-modal">
                    <div class="modal-header">
                        <div class="cl-rv-who">
                            <strong id="clRvModalTitle" class="cl-rv-m-author"></strong>
                            <small class="cl-sub cl-rv-m-date"></small>
                        </div>
                        <span class="cl-rv-pill cl-rv-m-rating ms-auto me-2" hidden></span>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body cl-rv-md"></div>
                    <div class="modal-footer justify-content-between">
                        <a class="cl-rv-m-link small" href="#" target="_blank" rel="noopener noreferrer">Lihat di TMDB <i class="bi bi-box-arrow-up-right"></i></a>
                        <button type="button" class="btn btn-ghost btn-sm" data-bs-dismiss="modal">Tutup</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function () {
            var root = document.getElementById('ulasan');
            var grid = root && root.querySelector('.cl-rv-grid');
            if (!grid) return;

            var cards = [].slice.call(grid.children);
            var more = root.querySelector('.cl-rv-more');
            var moreN = root.querySelector('.cl-rv-more-n');
            var empty = root.querySelector('.cl-rv-empty');
            var chips = [].slice.call(root.querySelectorAll('.cl-rv-chip'));
            var select = root.querySelector('[data-sort]');
            var STEP = 6, shown = STEP, tone = 'all', sort = 'new';

            function rating(c, missing) {
                return c.dataset.rating === '' ? missing : parseFloat(c.dataset.rating);
            }
            function ts(c) { return parseInt(c.dataset.ts, 10) || 0; }

            // tombol "Baca selengkapnya" hanya muncul bila teks benar-benar terpotong
            function trim() {
                cards.forEach(function (c) {
                    if (c.hidden) return;
                    var text = c.querySelector('.cl-rv-text');
                    var btn = c.querySelector('.cl-rv-open');
                    btn.hidden = text.scrollHeight <= text.clientHeight + 1;
                });
            }

            function render() {
                var list = cards.filter(function (c) { return tone === 'all' || c.dataset.tone === tone; });
                list.sort(function (a, b) {
                    if (sort === 'high') return (rating(b, -1) - rating(a, -1)) || (ts(b) - ts(a));
                    if (sort === 'low') return (rating(a, 99) - rating(b, 99)) || (ts(b) - ts(a));
                    return ts(b) - ts(a);
                });
                cards.forEach(function (c) { c.hidden = true; });
                list.forEach(function (c, i) {
                    grid.appendChild(c);
                    c.hidden = i >= shown;
                });
                var rest = list.length - shown;
                more.hidden = rest <= 0;
                moreN.textContent = Math.max(rest, 0);
                empty.hidden = list.length > 0;
                trim();
            }

            chips.forEach(function (chip) {
                chip.addEventListener('click', function () {
                    tone = chip.dataset.tone;
                    shown = STEP;
                    chips.forEach(function (x) {
                        var on = x === chip;
                        x.classList.toggle('is-active', on);
                        x.setAttribute('aria-pressed', on ? 'true' : 'false');
                    });
                    render();
                });
            });
            select.addEventListener('change', function () { sort = select.value; shown = STEP; render(); });
            more.addEventListener('click', function () { shown += STEP; render(); });

            // isi jendela baca
            var modal = document.getElementById('clRvModal');
            document.body.appendChild(modal);   // panel memakai backdrop-filter, yang membuat modal 'fixed' salah posisi bila tetap di dalamnya
            modal.addEventListener('show.bs.modal', function (e) {
                var card = e.relatedTarget && e.relatedTarget.closest('.cl-rv');
                if (!card) return;
                modal.querySelector('.cl-rv-m-author').textContent = card.dataset.author;
                modal.querySelector('.cl-rv-m-date').textContent = card.dataset.date;

                var pill = modal.querySelector('.cl-rv-m-rating');
                var r = card.dataset.rating;
                if (r !== '') {
                    pill.className = 'cl-rv-pill cl-rv-m-rating ms-auto me-2 cl-tone-' + card.dataset.tone;
                    pill.textContent = '';
                    var icon = document.createElement('i');
                    icon.className = 'bi bi-star-fill';
                    pill.appendChild(icon);
                    pill.appendChild(document.createTextNode(' ' + parseFloat(r).toFixed(1)));
                    pill.hidden = false;
                } else {
                    pill.hidden = true;
                }

                var body = modal.querySelector('.cl-rv-md');
                body.innerHTML = card.querySelector('template').innerHTML;   // markdown sudah dirender aman di server (HTML mentah dibuang)
                [].forEach.call(body.querySelectorAll('a'), function (a) { a.target = '_blank'; a.rel = 'noopener noreferrer'; });

                var link = modal.querySelector('.cl-rv-m-link');
                link.hidden = !card.dataset.url;
                link.href = card.dataset.url || '#';
            });

            render();
            window.addEventListener('load', trim);
            if (document.fonts && document.fonts.ready) document.fonts.ready.then(trim);
        })();
        </script>
    @endif
</div>
