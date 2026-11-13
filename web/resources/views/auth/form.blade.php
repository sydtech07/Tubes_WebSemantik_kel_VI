@extends('layouts.app')
@section('title', ($register ? 'Daftar' : 'Masuk') . ' - CINÉLOG')
@section('content')
<div class="cl-auth-wrap">
    <div class="cl-auth-story">
        <span class="cl-kicker">Ruang untuk pencinta film</span>
        <h1>Setiap film punya cerita.<br><span class="cl-accent">Apa ceritamu?</span></h1>
        <p class="cl-sub">Beri rating, bagikan kesan, dan simpan film yang ingin kamu tonton. Semua dalam satu tempat.</p>
        <div class="d-flex flex-wrap gap-2 mt-4">
            <span class="cl-chip"><i class="bi bi-star"></i> Rating pribadi</span>
            <span class="cl-chip"><i class="bi bi-chat-quote"></i> Ulasanmu</span>
            <span class="cl-chip"><i class="bi bi-bookmark"></i> Watchlist</span>
        </div>
    </div>
    <section class="cl-panel cl-auth-card">
        <div class="cl-auth-tabs mb-4">
            <a href="{{ route('login') }}" class="{{ ! $register ? 'active' : '' }}">Masuk</a>
            <a href="{{ route('register') }}" class="{{ $register ? 'active' : '' }}">Daftar</a>
        </div>
        <h2>{{ $register ? 'Mulai perjalanan filmmu' : 'Selamat datang kembali' }}</h2>
        <p class="cl-sub small mb-4">{{ $register ? 'Buat akun dan temukan film favorit berikutnya.' : 'Masuk untuk melanjutkan koleksi dan ulasanmu.' }}</p>
        <form method="post" action="{{ route($register ? 'register.store' : 'login.store') }}" class="cl-account-form">
            @csrf
            @if ($register)
                <label for="name" class="form-label">Nama</label>
                <input id="name" name="name" class="form-control mb-3" value="{{ old('name') }}" maxlength="100" autocomplete="name" required>
            @endif
            <label for="email" class="form-label">Email</label>
            <input id="email" name="email" type="email" class="form-control mb-3" value="{{ old('email') }}" maxlength="190" autocomplete="email" required>
            <label for="password" class="form-label">Kata sandi</label>
            <input id="password" name="password" type="password" class="form-control mb-3" minlength="{{ $register ? 8 : 1 }}" maxlength="72" autocomplete="{{ $register ? 'new-password' : 'current-password' }}" required>
            @if ($register)
                <p class="cl-sub small">Gunakan minimal 8 karakter.</p>
                <label for="password_confirmation" class="form-label">Ulangi kata sandi</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="form-control mb-4" minlength="8" maxlength="72" autocomplete="new-password" required>
            @else
                <div class="form-check mb-4">
                    <input id="remember" name="remember" type="checkbox" value="1" class="form-check-input" @checked(old('remember'))>
                    <label for="remember" class="form-check-label small">Ingat saya</label>
                </div>
            @endif
            <button class="btn btn-cl w-100" type="submit">{{ $register ? 'Buat akun' : 'Masuk' }} <i class="bi bi-arrow-right ms-2"></i></button>
        </form>
        <a href="{{ route('home') }}" class="d-block text-center cl-sub small mt-4">Jelajahi katalog</a>
    </section>
</div>
@endsection
