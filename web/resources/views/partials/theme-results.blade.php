{{-- Hasil pencarian tema (kata kunci + sinopsis). Masukan: $theme (concepts, films), $ratings --}}
@if (! empty($theme['films']))
    <h2 class="h5 mt-4 mb-3">Film bertema <small class="text-muted">dari kata kunci dan sinopsis</small></h2>
    <div class="text-muted small mb-3">
        Tema yang dikenali:
        @foreach ($theme['concepts'] as $c)
            <span class="cl-chip">{{ $c['label'] }}</span>
        @endforeach
    </div>
    <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 row-cols-xl-6 g-3 g-md-4">
        @foreach ($theme['films'] as $i => $m)
            @include('partials.film-card', ['m' => $m, 'r' => $ratings->get($m['slug']), 'note' => $m['note'], 'i' => $i])
        @endforeach
    </div>
@endif