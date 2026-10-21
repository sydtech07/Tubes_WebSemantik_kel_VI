@extends('layouts.app')

@section('title', 'Status Sistem - CINÉLOG')

@section('content')
    <h1 class="h4 mb-3">Status koneksi</h1>
    @foreach ($checks as $check)
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">
                <strong>{{ $check['name'] }}</strong>
                <span class="badge {{ $check['ok'] ? 'text-bg-success' : 'text-bg-danger' }}">{{ $check['ok'] ? 'TERHUBUNG' : 'GAGAL' }}</span>
            </div>
            <div class="card-body">
                @if ($check['ok'])
                    <table class="table table-sm mb-0">
                        @foreach ($check['detail'] as $label => $value)
                            <tr><th class="w-50">{{ $label }}</th><td>{{ $value }}</td></tr>
                        @endforeach
                    </table>
                @else
                    <pre class="mb-0 text-danger" style="white-space:pre-wrap">{{ $check['detail'] }}</pre>
                @endif
            </div>
        </div>
    @endforeach
@endsection
