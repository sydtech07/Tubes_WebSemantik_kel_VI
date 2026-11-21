@if ($data['status'] === 'ready')
    <dl class="mb-3">
        @foreach ($data['groups'] as $group)
            <dt class="small cl-sub mb-1">{{ $group['label'] }}</dt>
            <dd class="mb-3">
                @foreach ($group['items'] as $item)
                    <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer">{{ $item['label'] }}</a>@if (! $loop->last), @endif
                @endforeach
            </dd>
        @endforeach
    </dl>
@elseif ($data['status'] === 'empty')
    <p class="cl-sub small">Informasi tambahan untuk film ini belum tersedia di Wikidata.</p>
@elseif ($data['status'] === 'unlinked')
    <p class="cl-sub small mb-0">Film ini belum terhubung dengan Wikidata.</p>
@else
    <p class="cl-sub small">Wikidata sedang tidak dapat dihubungi. Informasi utama film tetap tersedia.</p>
    <button type="button" class="btn btn-ghost btn-sm mb-3" data-wikidata-retry>Coba lagi</button>
@endif
@if (isset($data['url']))
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ $data['url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-sm">Sumber: Wikidata <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
        <a href="{{ $data['query_url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-sm">Lihat kueri</a>
    </div>
    @if (isset($data['fetched_at']))
        <p class="cl-sub small mt-3 mb-0">Diambil {{ \Illuminate\Support\Carbon::parse($data['fetched_at'])->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB · Wikidata</p>
    @endif
@endif
