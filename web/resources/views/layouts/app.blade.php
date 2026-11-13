<!doctype html>
<html lang="id" data-bs-theme="dark">
<head prefix="og: https://ogp.me/ns# video: https://ogp.me/ns/video#">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0a0d14">
    <title>@yield('title', 'CINÉLOG')</title>
    <meta name="description" content="@yield('description', 'Katalog dan ulasan film rilis Januari-September 2026, ditenagai data semantik (RDF/SPARQL).')">

    {{-- Ikon emoji bawaan (juga mencegah browser meminta /favicon.ico ke server PHP) --}}
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎬</text></svg>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/cinelog.css') }}?v={{ @filemtime(public_path('css/cinelog.css')) }}" rel="stylesheet">
        <style>
        .cl-suggest { position: absolute; left: 0; right: 0; top: 100%; z-index: 1050; margin-top: .25rem; overflow: hidden;
            background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: .5rem; box-shadow: 0 .75rem 2rem rgba(0,0,0,.45); }
        .cl-suggest a { display: flex; justify-content: space-between; gap: .75rem; padding: .5rem .85rem; color: var(--bs-body-color); text-decoration: none; }
        .cl-suggest a small { color: var(--bs-secondary-color); }
        .cl-suggest a:hover, .cl-suggest a.active { background: var(--bs-tertiary-bg); }
        .cl-suggest .cl-suggest-all { justify-content: center; border-top: 1px solid var(--bs-border-color); font-size: .85rem; color: var(--bs-secondary-color); }
        @media (min-width: 768px) { .cl-search { min-width: 22rem; } }
        .cl-cast-name { display: block; color: inherit; text-decoration: none; }
        a.cl-cast-name:hover, dd a:hover { text-decoration: underline; }
    </style>
    @stack('head')
</head>
<body>
<nav class="navbar navbar-expand-md navbar-dark cl-navbar sticky-top">
    <div class="container-fluid cl-nav-container">
        <a class="navbar-brand cl-brand" href="{{ route('home') }}">CINÉLOG</a>

        {{-- Tombol hamburger: tampil hanya di layar kecil (HP) --}}
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#clNav"
                aria-controls="clNav" aria-expanded="false" aria-label="Buka menu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="clNav">
            <ul class="navbar-nav me-auto mb-2 mb-md-0">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('home', 'film.show', 'search', 'person.show') ? 'active' : '' }}" href="{{ route('home') }}">Katalog</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('cinemas') ? 'active' : '' }}" href="{{ route('cinemas') }}">Bioskop</a>
                </li>
            </ul>
                       <form class="cl-search position-relative" role="search" action="{{ route('search') }}" method="get">
                <div class="input-group">
                    <input class="form-control" type="search" name="q" value="{{ request()->string('q')->toString() }}"
                           placeholder="Cari film, aktor, sutradara, karakter, genre" aria-label="Cari" autocomplete="off"
                           data-suggest-url="{{ route('search.suggest', [], false) }}">
                    <button class="btn" type="submit" aria-label="Cari"><i class="bi bi-search"></i></button>
                </div>
                <div id="clSuggest" class="cl-suggest" role="listbox" hidden></div>
            </form>
            <div class="d-flex flex-wrap align-items-center gap-2 ms-md-3 py-2 py-md-0">
                @auth
                    @if (auth()->user()->isAdmin())<a class="nav-link small {{ request()->routeIs('admin.*') ? 'text-white' : 'cl-sub' }}" href="{{ route('admin.dashboard') }}">Admin</a>@endif
                    <a class="btn btn-ghost btn-sm cl-profile-link" href="{{ route('profile') }}"><i class="bi bi-person-circle"></i> Profil</a>
                    <form action="{{ route('logout') }}" method="post">@csrf<button class="btn btn-link cl-sub small p-1" type="submit">Keluar</button></form>
                @else
                    <a class="nav-link small cl-sub" href="{{ route('login') }}">Masuk</a>
                    <a class="btn btn-cl btn-sm" href="{{ route('register') }}">Daftar</a>
                @endauth
            </div>
        </div>
    </div>
</nav>

{{-- Halaman yang butuh lebar penuh (hero) memakai section 'bleed'; halaman lain memakai 'content' di dalam container --}}
@if (session('status') || $errors->any())
    <div class="container mt-3" role="status">
        @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        @if ($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    </div>
@endif
@hasSection('bleed')
    <main>@yield('bleed')</main>
@else
    <main class="container py-4">@yield('content')</main>
@endif

<footer class="cl-footer">
    <div class="container text-center text-md-start">
        <strong>CINÉLOG</strong> · katalog film berbasis data semantik (RDF, SPARQL, Fuseki).
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
<script>
(function () {
    var input = document.querySelector('input[data-suggest-url]');
    var box = document.getElementById('clSuggest');
    if (!input || !box) return;
    var timer = null, ctrl = null, active = -1;
    var LABEL = { film: 'Film', orang: 'Orang', genre: 'Genre' };

    function close() { box.hidden = true; box.textContent = ''; active = -1; }
    function render(items) {
        box.textContent = '';
        if (!items.length) { close(); return; }
        items.forEach(function (it) {
            var a = document.createElement('a');
            a.href = it.url; a.setAttribute('role', 'option');
            var s = document.createElement('span'); s.textContent = it.label;
            var k = document.createElement('small'); k.textContent = LABEL[it.kind] || it.kind;
            a.appendChild(s); a.appendChild(k); box.appendChild(a);
        });
        var all = document.createElement('a');
        all.href = input.form.action + '?q=' + encodeURIComponent(input.value.trim());
        all.className = 'cl-suggest-all'; all.textContent = 'Lihat semua hasil untuk "' + input.value.trim() + '"';
        box.appendChild(all);
        box.hidden = false; active = -1;
    }
    function fetchSuggest() {
        var q = input.value.trim();
        if (q.length < 2) { close(); return; }
        if (ctrl) ctrl.abort();
        ctrl = new AbortController();
        fetch(input.dataset.suggestUrl + '?q=' + encodeURIComponent(q), { signal: ctrl.signal, headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(render)
            .catch(function () {});
    }
    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(fetchSuggest, 200); });
    input.addEventListener('keydown', function (e) {
        var links = box.querySelectorAll('a');
        if (e.key === 'Escape') { close(); return; }
        if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && links.length && !box.hidden) {
            e.preventDefault();
            active = (active + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
            links.forEach(function (l, i) { l.classList.toggle('active', i === active); });
        } else if (e.key === 'Enter' && active >= 0 && links[active]) {
            e.preventDefault(); window.location = links[active].href;
        }
    });
    document.addEventListener('click', function (e) { if (!input.form.contains(e.target)) close(); });
})();
</script>
<!-- Widget Chatbot CINÉLOG (gaya: public/css/cinelog.css, bagian .cl-chat) -->
<div id="clChat" class="cl-chat" data-endpoint="{{ route('chatbot.send', [], false) }}" data-token="{{ csrf_token() }}">
    <button id="clChatToggle" class="cl-chat-fab" type="button" aria-expanded="false" aria-controls="clChatPanel">
        <i class="bi bi-chat-dots-fill" aria-hidden="true"></i><span>Tanya CINÉLOG</span>
    </button>

    <section id="clChatPanel" class="cl-chat-panel" role="dialog" aria-label="Asisten film CINÉLOG" hidden>
        <header class="cl-chat-head">
            <span class="cl-chat-avatar" aria-hidden="true"><i class="bi bi-film"></i></span>
            <div class="cl-chat-title">
                <strong>CINÉLOG Bot</strong>
                <small>Asisten film &middot; dibantu AI</small>
            </div>
            <button type="button" class="cl-chat-icon" id="clChatClear" title="Mulai percakapan baru" aria-label="Mulai percakapan baru"><i class="bi bi-arrow-counterclockwise"></i></button>
            <button type="button" class="cl-chat-icon" id="clChatClose" aria-label="Tutup chat"><i class="bi bi-x-lg"></i></button>
        </header>

        <div id="clChatLog" class="cl-chat-log" aria-live="polite"></div>

        <div class="cl-chat-chips" id="clChatChips">
            <button type="button" class="cl-chat-chip" data-q="Rekomendasikan film action terbaik tahun 2026">Film action</button>
            <button type="button" class="cl-chat-chip" data-q="Film horor apa yang seru ditonton?">Film horor</button>
            <button type="button" class="cl-chat-chip" data-q="Rekomendasi film keluarga untuk akhir pekan">Film keluarga</button>
            <a class="cl-chat-chip is-link" href="{{ route('cinemas') }}"><i class="bi bi-geo-alt-fill"></i> Bioskop di Sumut</a>
        </div>

        <form id="clChatForm" class="cl-chat-form" autocomplete="off">
            <input id="clChatInput" type="text" maxlength="1000" placeholder="Tanya soal film atau minta rekomendasi" aria-label="Pesan untuk chatbot">
            <button type="submit" class="cl-chat-send" aria-label="Kirim pesan"><i class="bi bi-send-fill"></i></button>
        </form>
        <p class="cl-chat-note">Jawaban AI bisa keliru. Cek detail film di katalog.</p>
    </section>
</div>

<script>
(function () {
    var root = document.getElementById('clChat');
    if (!root) return;
    var panel = document.getElementById('clChatPanel');
    var toggle = document.getElementById('clChatToggle');
    var log = document.getElementById('clChatLog');
    var form = document.getElementById('clChatForm');
    var input = document.getElementById('clChatInput');
    var chips = document.getElementById('clChatChips');
    var KEY = 'cl-chat-v1', KEY_OPEN = 'cl-chat-open';
    var WELCOME = 'Halo! Saya asisten CINÉLOG. Mau cari rekomendasi atau info film apa hari ini?';
    var history = [], busy = false;

    function store(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
    function load(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }

    // ---- format ringan balasan AI (**tebal**, daftar berpoin, paragraf) tanpa innerHTML ----
    function inline(parent, text) {
        text.split(/(\*\*[^*]+\*\*)/g).forEach(function (part) {
            if (/^\*\*[^*]+\*\*$/.test(part)) {
                var b = document.createElement('strong'); b.textContent = part.slice(2, -2); parent.appendChild(b);
            } else if (part) {
                parent.appendChild(document.createTextNode(part.replace(/\*/g, '')));
            }
        });
    }
    function format(box, text) {
        var ul = null, p = null;
        text.split(/\r?\n/).forEach(function (line) {
            var li = line.match(/^\s*[*\-•]\s+(.*)$/);
            if (li) {
                p = null;
                if (!ul) { ul = document.createElement('ul'); box.appendChild(ul); }
                var item = document.createElement('li'); inline(item, li[1]); ul.appendChild(item);
            } else if (line.trim() === '') {
                ul = null; p = null;
            } else {
                ul = null;
                if (!p) { p = document.createElement('p'); box.appendChild(p); } else { p.appendChild(document.createElement('br')); }
                inline(p, line.trim());
            }
        });
    }

    function bubble(role, text) {
        var d = document.createElement('div');
        d.className = 'cl-chat-msg is-' + role;
        if (role === 'bot') format(d, text); else d.textContent = text;
        log.appendChild(d);
        log.scrollTop = log.scrollHeight;
        return d;
    }
    function typing() {
        var d = document.createElement('div');
        d.className = 'cl-chat-msg is-bot is-typing';
        d.setAttribute('aria-label', 'Bot sedang mengetik');
        d.innerHTML = '<span></span><span></span><span></span>';
        log.appendChild(d); log.scrollTop = log.scrollHeight;
        return d;
    }
    function persist() { store(KEY, JSON.stringify(history.slice(-40))); }

    function restore() {
        log.textContent = '';
        try { history = JSON.parse(load(KEY) || '[]') || []; } catch (e) { history = []; }
        if (!history.length) { bubble('bot', WELCOME); chips.hidden = false; return; }
        history.forEach(function (m) { bubble(m.role, m.text); });
        chips.hidden = true;
    }

    function setOpen(open) {
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        root.classList.toggle('is-open', open);
        store(KEY_OPEN, open ? '1' : '0');
        if (open) { log.scrollTop = log.scrollHeight; setTimeout(function () { input.focus(); }, 50); }
    }

    async function send(text) {
        if (busy || !text) return;
        busy = true; input.disabled = true; chips.hidden = true;
        bubble('user', text); history.push({ role: 'user', text: text }); persist();
        var wait = typing();
        try {
            var res = await fetch(root.dataset.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': root.dataset.token },
                body: JSON.stringify({ message: text })
            });
            var data = await res.json().catch(function () { return {}; });
            wait.remove();
            if (res.status === 429) throw new Error('Terlalu banyak pesan dalam waktu singkat. Tunggu sebentar lalu coba lagi.');
            if (!res.ok || typeof data.reply !== 'string') throw new Error('Terjadi kesalahan pada server. Coba lagi nanti.');
            bubble('bot', data.reply); history.push({ role: 'bot', text: data.reply }); persist();
        } catch (err) {
            wait.remove();
            var msg = err && err.message && err.message.indexOf('Failed to fetch') === -1 ? err.message : 'Tidak dapat terhubung ke server.';
            bubble('error', msg);
        } finally {
            busy = false; input.disabled = false; input.focus();
        }
    }

    toggle.addEventListener('click', function () { setOpen(panel.hidden); });
    document.getElementById('clChatClose').addEventListener('click', function () { setOpen(false); toggle.focus(); });
    document.getElementById('clChatClear').addEventListener('click', function () { history = []; persist(); restore(); input.focus(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { setOpen(false); toggle.focus(); } });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = input.value.trim();
        if (!text) return;
        input.value = '';
        send(text);
    });
    chips.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-q]');
        if (b) send(b.dataset.q);
    });

    restore();
    if (load(KEY_OPEN) === '1') setOpen(true);
})();
</script>
<script>
document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});
</script>
</body>
</html>
