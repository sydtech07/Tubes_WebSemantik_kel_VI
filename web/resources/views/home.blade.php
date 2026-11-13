@extends('layouts.app')

@section('title', 'Katalog Film 2026 - CINÉLOG')

@section('bleed')
@php
    $genreAktif = collect($genreIds)->map(fn ($id) => collect($genres)->firstWhere('id', $id))->filter();
    // URL katalog dengan sebagian parameter diubah; parameter kosong dibuang; halaman selalu kembali ke 1
    $ubah = fn (array $p) => route('home', array_filter(
        array_merge(request()->except('page'), $p),
        fn ($v) => $v !== null && $v !== ''
    ));
    $urutan = ['populer' => 'Terpopuler', 'terbaru' => 'Terbaru', 'skor' => 'Skor TMDB', 'judul' => 'Judul A-Z'];
    // warna cincin skor: hijau (>= 7), kuning (>= 5), merah
    $warna = fn (float $v) => $v >= 7 ? '#2ecc71' : ($v >= 5 ? '#ffc83d' : '#ff4d4d');
@endphp

{{-- ===== Hero carousel: 5 film terpopuler (hanya di beranda polos) ===== --}}
@if (! empty($featured))
    <section id="clHero" class="carousel slide carousel-fade cl-carousel" data-bs-ride="carousel" data-bs-interval="6500" aria-label="Film unggulan">
        <div class="carousel-indicators">
            @foreach ($featured as $i => $f)
                <button type="button" data-bs-target="#clHero" data-bs-slide-to="{{ $i }}"
                        class="{{ $i === 0 ? 'active' : '' }}" aria-label="Film unggulan {{ $i + 1 }}"
                        @if ($i === 0) aria-current="true" @endif></button>
            @endforeach
        </div>
        <div class="carousel-inner">
            @foreach ($featured as $i => $f)
                <div class="carousel-item {{ $i === 0 ? 'active' : '' }}">
                    <div class="cl-slide">
                        <img class="cl-slide-bg" src="{{ $f['backdrop'] }}" alt="" @if ($i > 0) loading="lazy" @endif>
                        <div class="container cl-slide-body">
                            <div class="cl-slide-inner">
                                <span class="cl-kicker"><i class="bi bi-fire"></i> #{{ $i + 1 }} Terpopuler</span>
                                <h2 class="cl-slide-title">{{ $f['title'] }}</h2>
                                <div class="cl-slide-meta">
                                    @if ($f['vote']) <span><i class="bi bi-star-fill"></i> {{ number_format($f['vote'], 1) }}</span> @endif
                                    <span>{{ \Illuminate\Support\Carbon::parse($f['date'])->locale('id')->translatedFormat('d F Y') }}</span>
                                    @if ($f['runtime']) <span>{{ $f['runtime'] }} menit</span> @endif
                                </div>
                                @if ($f['description'])
                                    <p class="cl-slide-desc">{{ $f['description'] }}</p>
                                @endif
                                <a href="{{ route('film.show', $f['slug']) }}" class="btn btn-cl">Lihat detail <i class="bi bi-arrow-right"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif

<div class="container py-4">
    @if ($error)
        <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>{{ $error }}</div>
        </div>
    @endif

    {{-- Judul + jumlah + urutan. Di HP menumpuk ke bawah, di layar lebar sejajar. --}}
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-end gap-3 mb-3">
        <div>
            <h1 class="cl-section-title">
                @if ($q !== '')
                    Hasil pencarian "{{ $q }}"
                @elseif ($genreAktif->isNotEmpty())
                    Genre {{ $genreAktif->pluck('label')->join(' + ') }}
                @else
                    Jelajahi film 2026
                @endif
            </h1>
            <div class="text-muted small mt-2">{{ number_format($total, 0, ',', '.') }} film</div>
        </div>

        <form method="get" action="{{ route('home') }}" class="d-flex align-items-center gap-2">
            @if ($q !== '') <input type="hidden" name="q" value="{{ $q }}"> @endif
            @foreach ($genreIds as $id)<input type="hidden" name="genre[]" value="{{ $id }}">@endforeach
            <label for="urutan" class="small text-muted mb-0"><i class="bi bi-sort-down"></i> Urutkan</label>
            <select id="urutan" name="sort" class="form-select form-select-sm w-auto cl-select" onchange="this.form.submit()">
                @foreach ($urutan as $kunci => $label)
                    <option value="{{ $kunci }}" @selected($sort === $kunci)>{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <form class="cl-genre-filter mb-3" method="get" action="{{ route('home') }}">
        @if ($q !== '')<input type="hidden" name="q" value="{{ $q }}">@endif
        <input type="hidden" name="sort" value="{{ $sort }}">
        <div class="cl-genre-field">
            <label class="form-label" for="genre-first"><i class="bi bi-funnel"></i> Genre</label>
            <select class="form-select" name="genre[]" id="genre-first">
                <option value="">Semua genre</option>
                @foreach ($genres as $g)<option value="{{ $g['id'] }}" @selected(($genreIds[0] ?? null) === $g['id'])>{{ $g['label'] }} ({{ number_format($g['n'], 0, ',', '.') }} film)</option>@endforeach
            </select>
        </div>
        <div class="cl-genre-field">
            <label class="form-label" for="genre-second">Gabungkan dengan <span class="cl-sub">(opsional)</span></label>
            <select class="form-select" name="genre[]" id="genre-second">
                <option value="">Tanpa genre tambahan</option>
                @foreach ($genres as $g)<option value="{{ $g['id'] }}" @selected(($genreIds[1] ?? null) === $g['id'])>{{ $g['label'] }} ({{ number_format($g['n'], 0, ',', '.') }} film)</option>@endforeach
            </select>
        </div>
        <div class="cl-genre-actions">
            <button class="btn btn-cl" type="submit">Terapkan filter</button>
            @if ($genreIds !== [])<a class="btn btn-ghost" href="{{ $ubah(['genre' => null]) }}">Reset genre</a>@endif
        </div>
    </form>
    <p class="cl-sub small mb-4">Pilih hingga dua genre. Film harus memiliki kedua genre yang dipilih. Angka pada pilihan menunjukkan jumlah film per genre di katalog.</p>
    @if ($genreAktif->isNotEmpty())
        <div class="cl-selected-genres mb-4" aria-label="Genre terpilih">
            @foreach ($genreAktif as $g)
                <a class="cl-chip active" href="{{ $ubah(['genre' => array_values(array_diff($genreIds, [$g['id']]))]) }}" aria-label="Hapus filter {{ $g['label'] }}">{{ $g['label'] }} <small>{{ $g['n'] }}</small> <i class="bi bi-x"></i></a>
            @endforeach
        </div>
    @endif

    @if (! $error && $total === 0)
        <div class="cl-panel text-center py-5">
            <i class="bi bi-film display-4 text-muted"></i>
            <p class="mt-3 mb-1 fw-semibold">Tidak ada film yang cocok.</p>
            <p class="text-muted small mb-3">
                Coba kata kunci lain
                @if ($q !== '' || $genreIds !== []) atau hapus filter @endif.
            </p>
            @if ($q !== '' || $genreIds !== [])
                <a href="{{ route('home') }}" class="btn btn-cl btn-sm">Hapus filter</a>
            @else
                <p class="small text-muted mb-0">
                    Jika katalog benar-benar kosong, pastikan data sudah dimuat ke Fuseki
                    (<code>python pipeline\05_load_fuseki.py</code>) lalu muat ulang halaman ini.
                </p>
            @endif
        </div>
    @endif

    {{-- Grid: 2 kolom di HP, 3 di tablet kecil, 4, 5, lalu 6 di layar lebar --}}
    <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 row-cols-xl-6 g-3 g-md-4">
        @foreach ($items as $m)
            @php $r = $ratings->get($m['slug']); @endphp
            <div class="col cl-col" style="--i: {{ $loop->index % 12 }}">
                <a href="{{ route('film.show', $m['slug']) }}" class="cl-card">
                    <div class="cl-poster-wrap">
                        <div class="cl-poster">
                            @if ($m['poster'])
                                <img src="{{ $m['poster'] }}" alt="Poster {{ $m['title'] }}" loading="lazy">
                            @else
                                <div class="cl-poster-empty">tanpa poster</div>
                            @endif
                            <div class="cl-overlay"><span><i class="bi bi-play-circle-fill"></i> Lihat detail</span></div>
                        </div>
                        @if ($m['vote'])
                            <div class="cl-ring" title="Skor TMDB" style="--p: {{ round($m['vote'] * 10) }}; --c: {{ $warna($m['vote']) }}">
                                <span>{{ number_format($m['vote'], 1) }}</span>
                            </div>
                        @endif
                    </div>
                    <div class="cl-card-body">
                        <div class="cl-title">{{ $m['title'] }}</div>
                        <div class="cl-meta">{{ \Illuminate\Support\Carbon::parse($m['date'])->locale('id')->translatedFormat('d M Y') }}</div>
                        <div class="cl-meta">
                            @if ($r && $r->reviews_count > 0)
                                <i class="bi bi-people-fill"></i> {{ number_format($r->avg_rating, 1) }} ({{ $r->reviews_count }} ulasan)
                            @elseif ($r && $r->external_reviews_count > 0)
                                <i class="bi bi-chat-quote-fill"></i> {{ $r->external_reviews_count }} ulasan TMDB
                            @else
                                belum ada ulasan
                            @endif
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="d-flex justify-content-center mt-5">{{ $paginator->links('pagination::bootstrap-5') }}</div>
</div>
@endsection
