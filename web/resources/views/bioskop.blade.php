@extends('layouts.app')

@section('title', 'Bioskop di Sumatera Utara - CINÉLOG')
@section('description', 'Peta dan daftar bioskop XXI, CGV, dan Cinépolis di Medan dan Sumatera Utara, lengkap dengan alamat dan petunjuk arah.')

@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
    <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
    <h1 class="cl-section-title">Bioskop di Sumatera Utara</h1>
    <p class="cl-sub mt-2 mb-4">
        <span id="bkCount">{{ count($cinemas) }}</span> bioskop XXI, CGV, dan Cinépolis, berpusat di Kota Medan.
        Klik bioskop untuk melihatnya di peta.
    </p>

    {{-- ===== Pencarian + filter ===== --}}
    <div class="cl-bk-toolbar">
        <div class="cl-bk-search">
            <i class="bi bi-search"></i>
            <input id="bkQuery" type="search" class="form-control" placeholder="Cari nama bioskop, mal, atau jalan" aria-label="Cari bioskop" autocomplete="off">
        </div>
        <select id="bkCity" class="form-select cl-bk-select" aria-label="Kota atau kabupaten">
            <option value="">Semua kota di Sumut</option>
            @foreach ($cities as $city => $n)
                <option value="{{ $city }}" @selected($city === 'Medan')>{{ $city }} ({{ $n }})</option>
            @endforeach
        </select>
        <div class="cl-bk-chips" role="group" aria-label="Jaringan bioskop">
            <button type="button" class="cl-rv-chip is-active" data-chain="" aria-pressed="true">Semua</button>
            <button type="button" class="cl-rv-chip" data-chain="XXI" aria-pressed="false"><i class="cl-bk-dot cl-chain-xxi"></i> XXI</button>
            <button type="button" class="cl-rv-chip" data-chain="CGV" aria-pressed="false"><i class="cl-bk-dot cl-chain-cgv"></i> CGV</button>
            <button type="button" class="cl-rv-chip" data-chain="Cinépolis" aria-pressed="false"><i class="cl-bk-dot cl-chain-cinepolis"></i> Cinépolis</button>
        </div>
        <button type="button" id="bkNear" class="btn btn-ghost btn-sm"><i class="bi bi-crosshair"></i> Dekat saya</button>
    </div>
    <p id="bkMsg" class="cl-sub small mt-2 mb-0" role="status" hidden></p>

    {{-- ===== Daftar + peta ===== --}}
    <div class="cl-bk-layout">
        <ul id="bkList" class="cl-bk-list" aria-label="Daftar bioskop"></ul>
        <div class="cl-bk-mapwrap">
            <div id="bkMap" class="cl-bk-map" role="region" aria-label="Peta bioskop"></div>
            <noscript><p class="cl-sub p-3">Peta membutuhkan JavaScript. Daftar bioskop tersedia di bawah.</p></noscript>
        </div>
    </div>

    <p class="cl-sub small mt-3 mb-0">
        <i class="bi bi-info-circle"></i>
        Posisi pada peta berupa perkiraan dan bisa meleset beberapa ratus meter
        (bioskop bertanda <span class="cl-bk-est">perkiraan</span> belum dicek ke lokasi).
        Tombol <strong>Rute</strong> membuka Google Maps berdasarkan nama bioskop sehingga lokasinya tepat.
        Jadwal tayang dan harga tiket ada di situs resmi tiap jaringan. Data tidak terhubung langsung ke XXI, CGV, atau Cinépolis.
    </p>

    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    (function () {
        var DATA = @json($cinemas);
        var CHAIN = {
            'XXI':       { cls: 'cl-chain-xxi',       label: 'XXI',  color: '#f5a524' },
            'CGV':       { cls: 'cl-chain-cgv',       label: 'CGV',  color: '#ff4d4d' },
            'Cinépolis': { cls: 'cl-chain-cinepolis', label: 'CP',   color: '#3b8cff' }
        };

        var elList = document.getElementById('bkList');
        var elCount = document.getElementById('bkCount');
        var elQuery = document.getElementById('bkQuery');
        var elCity = document.getElementById('bkCity');
        var elMsg = document.getElementById('bkMsg');
        var elNear = document.getElementById('bkNear');
        var chips = [].slice.call(document.querySelectorAll('[data-chain]'));

        var state = { q: '', city: elCity.value, chain: '', user: null, active: null };

        // ---------- Peta ----------
        var map = null, layer = null, userMarker = null, markers = {};
        if (window.L) {
            map = L.map('bkMap', { scrollWheelZoom: false }).setView([3.5896, 98.6738], 12);
            L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
                subdomains: 'abcd', maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>'
            }).addTo(map);
            layer = L.layerGroup().addTo(map);
            // scroll-zoom hanya setelah peta diklik, supaya tidak "menyandera" scroll halaman
            map.on('click', function () { map.scrollWheelZoom.enable(); });
            map.on('mouseout', function () { map.scrollWheelZoom.disable(); });
        } else {
            document.getElementById('bkMap').textContent = 'Peta gagal dimuat (periksa koneksi internet). Daftar bioskop tetap bisa dipakai.';
        }

        function mapsQuery(c) { return encodeURIComponent(c.name + ', ' + c.city + ', Sumatera Utara'); }
        function el(tag, cls, text) {
            var n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text !== undefined) n.textContent = text;
            return n;
        }
        function link(href, text, icon, cls) {
            var a = el('a', cls || 'cl-bk-link');
            a.href = href; a.target = '_blank'; a.rel = 'noopener noreferrer';
            var i = el('i', 'bi ' + icon); a.appendChild(i);
            a.appendChild(document.createTextNode(' ' + text));
            return a;
        }
        function km(d) {
            return (d < 10 ? d.toFixed(1) : Math.round(d)).toString().replace('.', ',') + ' km';
        }
        function distance(a, b) {   // haversine, km
            var R = 6371, rad = Math.PI / 180;
            var dLat = (b.lat - a.lat) * rad, dLng = (b.lng - a.lng) * rad;
            var x = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                    Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
            return 2 * R * Math.asin(Math.sqrt(x));
        }

        function popupNode(c) {
            var box = el('div', 'cl-bk-pop');
            box.appendChild(el('strong', null, c.name));
            box.appendChild(el('div', 'cl-bk-pop-addr', c.address + ', ' + c.city));
            var row = el('div', 'cl-bk-pop-actions');
            row.appendChild(link('https://www.google.com/maps/dir/?api=1&destination=' + mapsQuery(c), 'Rute', 'bi-sign-turn-right'));
            row.appendChild(link('https://www.google.com/maps/search/?api=1&query=' + mapsQuery(c), 'Google Maps', 'bi-geo-alt'));
            row.appendChild(link(c.site, 'Jadwal', 'bi-calendar-event'));
            box.appendChild(row);
            return box;
        }

        function pinIcon(c) {
            var ch = CHAIN[c.chain] || { label: '?', color: '#98a2b8' };
            return L.divIcon({
                className: 'cl-bk-pinwrap',
                html: '<span class="cl-bk-pin" style="--pin:' + ch.color + '">' + ch.label + '</span>',
                iconSize: [38, 38], iconAnchor: [19, 19], popupAnchor: [0, -18]
            });
        }

        DATA.forEach(function (c) {
            if (!map) return;
            var m = L.marker([c.lat, c.lng], { icon: pinIcon(c), title: c.name, keyboard: true });
            m.bindPopup(function () { return popupNode(c); }, { maxWidth: 280 });
            m.on('click', function () { setActive(c.id, false); });
            markers[c.id] = m;
        });

        // ---------- Daftar ----------
        var items = {};
        function buildItem(c) {
            var li = el('li', 'cl-bk-item');
            li.dataset.id = c.id;
            var btn = el('button', 'cl-bk-itembtn');
            btn.type = 'button';

            var ch = CHAIN[c.chain] || { cls: '', label: c.chain };
            var head = el('div', 'cl-bk-itemhead');
            head.appendChild(el('span', 'cl-bk-chain ' + ch.cls, c.chain));
            head.appendChild(el('strong', null, c.name));
            var dist = el('span', 'cl-bk-dist');
            head.appendChild(dist);
            btn.appendChild(head);

            btn.appendChild(el('div', 'cl-sub small', c.address));
            var foot = el('div', 'cl-bk-itemfoot');
            foot.appendChild(el('span', 'cl-sub small', c.district + ', ' + c.city));
            if (c.coord === 'estimasi') foot.appendChild(el('span', 'cl-bk-est', 'perkiraan'));
            btn.appendChild(foot);
            btn.addEventListener('click', function () { setActive(c.id, true); });
            li.appendChild(btn);

            var actions = el('div', 'cl-bk-itemactions');
            actions.appendChild(link('https://www.google.com/maps/dir/?api=1&destination=' + mapsQuery(c), 'Rute', 'bi-sign-turn-right'));
            actions.appendChild(link(c.site, 'Jadwal', 'bi-calendar-event'));
            li.appendChild(actions);

            items[c.id] = { li: li, dist: dist };
            return li;
        }
        DATA.forEach(buildItem);

        function visible() {
            var q = state.q.trim().toLowerCase();
            var list = DATA.filter(function (c) {
                if (state.city && c.city !== state.city) return false;
                if (state.chain && c.chain !== state.chain) return false;
                if (!q) return true;
                return (c.name + ' ' + c.address + ' ' + c.district + ' ' + c.city + ' ' + c.chain).toLowerCase().indexOf(q) !== -1;
            });
            if (state.user) {
                list = list.slice().sort(function (a, b) { return distance(state.user, a) - distance(state.user, b); });
            }
            return list;
        }

        function render(fit) {
            var list = visible();
            elCount.textContent = list.length;
            elList.textContent = '';
            list.forEach(function (c) {
                var it = items[c.id];
                it.li.classList.toggle('is-active', state.active === c.id);
                it.dist.textContent = state.user ? km(distance(state.user, c)) : '';
                elList.appendChild(it.li);
            });
            if (!list.length) {
                var empty = el('li', 'cl-bk-empty cl-sub', 'Tidak ada bioskop yang cocok dengan filter ini.');
                elList.appendChild(empty);
            }

            if (!map) return;
            layer.clearLayers();
            list.forEach(function (c) { layer.addLayer(markers[c.id]); });
            if (fit !== false) fitTo(list);
        }

        function fitTo(list) {
            if (!map || !list.length) return;
            var pts = list.map(function (c) { return [c.lat, c.lng]; });
            if (state.user) pts.push([state.user.lat, state.user.lng]);
            if (pts.length === 1) { map.setView(pts[0], 15); return; }
            map.fitBounds(L.latLngBounds(pts).pad(0.18), { maxZoom: 15 });
        }

        function setActive(id, fromList) {
            state.active = id;
            Object.keys(items).forEach(function (k) { items[k].li.classList.toggle('is-active', k === id); });
            var it = items[id];
            if (it && !fromList) it.li.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            if (fromList && map && markers[id]) {
                var c = DATA.filter(function (x) { return x.id === id; })[0];
                map.flyTo([c.lat, c.lng], Math.max(map.getZoom(), 15), { duration: 0.6 });
                markers[id].openPopup();
                if (window.matchMedia('(max-width: 991.98px)').matches) {
                    document.getElementById('bkMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        }

        // ---------- Kontrol ----------
        var timer = null;
        elQuery.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { state.q = elQuery.value; render(); }, 150);
        });
        elCity.addEventListener('change', function () { state.city = elCity.value; render(); });
        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                state.chain = chip.dataset.chain;
                chips.forEach(function (x) {
                    var on = x === chip;
                    x.classList.toggle('is-active', on);
                    x.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
                render();
            });
        });

        function say(text) { elMsg.textContent = text || ''; elMsg.hidden = !text; }

        elNear.addEventListener('click', function () {
            if (state.user) {                       // tekan lagi = matikan
                state.user = null;
                if (userMarker && map) { map.removeLayer(userMarker); userMarker = null; }
                elNear.classList.remove('is-on');
                elNear.lastChild.textContent = ' Dekat saya';
                say('');
                render();
                return;
            }
            if (!navigator.geolocation) { say('Browser ini tidak mendukung penentuan lokasi.'); return; }
            say('Mencari lokasi Anda…');
            navigator.geolocation.getCurrentPosition(function (pos) {
                state.user = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                state.city = ''; elCity.value = '';     // cari di seluruh Sumut agar yang terdekat benar-benar terdekat
                if (map) {
                    if (userMarker) map.removeLayer(userMarker);
                    userMarker = L.circleMarker([state.user.lat, state.user.lng], {
                        radius: 8, color: '#fff', weight: 2, fillColor: '#ff3d71', fillOpacity: 1
                    }).addTo(map).bindTooltip('Lokasi Anda');
                }
                elNear.classList.add('is-on');
                elNear.lastChild.textContent = ' Matikan lokasi';
                var nearest = visible()[0];
                var jauh = nearest && distance(state.user, nearest) > 200;
                say(jauh
                    ? 'Lokasi Anda cukup jauh dari Sumatera Utara; bioskop diurutkan dari yang paling dekat.'
                    : 'Diurutkan dari yang paling dekat dengan lokasi Anda. Lokasi tidak dikirim ke server.');
                render(!jauh);
                if (jauh && map) map.setView([3.5896, 98.6738], 11);
            }, function (err) {
                say(err.code === 1
                    ? 'Izin lokasi ditolak. Aktifkan izin lokasi di browser untuk memakai fitur ini.'
                    : 'Lokasi tidak dapat ditentukan. Coba lagi.');
            }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
        });

        render();
    })();
    </script>
@endsection
