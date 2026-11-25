@extends('layouts.app')
@section('title', 'Notifikasi - CINÉLOG')
@section('content')
<h1 class="cl-section-title">Notifikasi</h1>
@forelse ($items as $item)
    <div class="cl-panel mb-2">
        <p class="mb-1">
            @if (in_array($item->id, $unread, true))<strong>Baru · </strong>@endif
            {{ $item->data['message'] ?? '' }}
        </p>
        <p class="cl-sub mb-1">{{ $item->created_at->format('d M Y H:i') }}</p>
        @if (! empty($item->data['url']))
            <a href="{{ $item->data['url'] }}">Lihat receipt</a>
        @endif
    </div>
@empty
    <p class="cl-sub">Belum ada notifikasi.</p>
@endforelse
{{ $items->links() }}
@endsection
