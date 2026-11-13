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
            <section class="cl-bk-route-panel" aria-label="Pengaturan rute">
                <h2><i class="bi bi-sign-turn-right"></i> Rute perjalanan</h2>
                <p id="bkDestination" class="mb-3 fw-semibold">Pilih tombol Rute pada bioskop tujuan.</p>
                <label for="bkTravel" class="visually-hidden">Jenis perjalanan</label>
                <select id="bkTravel" class="form-select form-select-sm mb-3">
                    <option value="driving-car">Mobil</option>
                    <option value="motorcycle">Motor</option>
                    <option value="foot-walking">Jalan kaki</option>
                    <option value="cycling-regular">Sepeda</option>
                </select>
                <div class="d-flex flex-wrap gap-2">
                    <button id="bkRouteLocate" type="button" class="btn btn-ghost btn-sm"><i class="bi bi-crosshair"></i> Gunakan lokasi saya</button>
                    <button id="bkRoutePick" type="button" class="btn btn-ghost btn-sm" aria-pressed="false"><i class="bi bi-pin-map"></i> Pilih titik awal di peta</button>
                    <button id="bkRouteClear" type="button" class="btn btn-link cl-sub btn-sm" hidden>Hapus rute</button>
                </div>
                <p id="bkOrigin" class="cl-sub small mt-3 mb-1">Titik awal belum dipilih.</p>
                <p id="bkRouteStatus" class="small mb-2" role="status" aria-live="polite">Rute akan ditampilkan langsung pada peta ini.</p>
                <p id="bkRoutePrivacy" class="cl-sub cl-bk-route-privacy mb-0">Saat menghitung rute, koordinat titik awal dan bioskop dikirim ke OpenRouteService.</p>
            </section>
            <div id="bkMap" class="cl-bk-map" role="region" aria-label="Peta bioskop"></div>
            <section id="bkRouteResult" class="cl-bk-route-result" aria-label="Hasil rute" hidden>
                <div class="d-flex gap-4 mb-2"><strong><i class="bi bi-signpost"></i> <span id="bkRouteDistance"></span></strong><strong><i class="bi bi-clock"></i> <span id="bkRouteDuration"></span></strong></div>
                <p class="cl-sub small mb-3">Perkiraan perjalanan, tidak memperhitungkan lalu lintas langsung.</p>
                <details><summary>Petunjuk perjalanan</summary><ol id="bkRouteSteps" class="cl-bk-route-steps mt-3 mb-0"></ol></details>
                <p class="cl-sub small mt-3 mb-0">Rute oleh <a id="bkRouteProvider" href="https://openrouteservice.org/" target="_blank" rel="noopener noreferrer">OpenRouteService</a> · data © OpenStreetMap contributors. <a href="https://www.openstreetmap.org/fixthemap" target="_blank" rel="noopener noreferrer">Laporkan masalah peta</a></p>
            </section>
            <noscript><p class="cl-sub p-3">Peta membutuhkan JavaScript. Daftar bioskop tersedia di bawah.</p></noscript>
        </div>
    </div>

    <p class="cl-sub small mt-3 mb-0">
        <i class="bi bi-info-circle"></i>
        Posisi pada peta berupa perkiraan dan bisa meleset beberapa ratus meter
        (bioskop bertanda <span class="cl-bk-est">perkiraan</span> belum dicek ke lokasi).
        Tombol <strong>Rute</strong> menghitung perjalanan ke koordinat bioskop pada peta ini. Perhatikan jalan akses dan pintu masuk mal saat tiba.
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
        var elDestination = document.getElementById('bkDestination');
        var elOrigin = document.getElementById('bkOrigin');
        var elRouteStatus = document.getElementById('bkRouteStatus');
        var elRoutePick = document.getElementById('bkRoutePick');
        var elRouteLocate = document.getElementById('bkRouteLocate');
        var elRouteClear = document.getElementById('bkRouteClear');
        var elRouteResult = document.getElementById('bkRouteResult');
        var elTravel = document.getElementById('bkTravel');
        var ROUTE_URL = @json(route('cinemas.route', [], false));
        var TOKEN = @json(csrf_token());
        var chips = [].slice.call(document.querySelectorAll('[data-chain]'));

        var state = { q: '', city: elCity.value, chain: '', user: null, active: null };
        var routing = { destination: null, origin: null, picking: false, request: null, sequence: 0, locationSequence: 0 };
        var routeLayer = null, originMarker = null;

        // ---------- Peta ----------
        var map = null, layer = null, userMarker = null, markers = {};
        if (window.L) {
            map = L.map('bkMap', { scrollWheelZoom: false }).setView([3.5896, 98.6738], 12);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>'
            }).addTo(map);
            layer = L.layerGroup().addTo(map);
            // scroll-zoom hanya setelah peta diklik, supaya tidak "menyandera" scroll halaman
            map.on('click', function (event) {
                map.scrollWheelZoom.enable();
                if (routing.picking) {
                    routing.locationSequence++;
                    elRouteLocate.disabled = false;
                    setOrigin({ lat: event.latlng.lat, lng: event.latlng.lng }, 'Titik awal dipilih pada peta.');
                }
            });
            map.on('mouseout', function () { map.scrollWheelZoom.disable(); });
        } else {
            document.getElementById('bkMap').textContent = 'Peta gagal dimuat (periksa koneksi internet). Daftar bioskop tetap bisa dipakai.';
        }

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
        function routeButton(c) {
            var button = el('button', 'cl-bk-link', '');
            button.type = 'button';
            button.setAttribute('aria-label', 'Rute ke ' + c.name);
            button.appendChild(el('i', 'bi bi-sign-turn-right'));
            button.appendChild(document.createTextNode(' Rute'));
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                beginRoute(c);
            });
            return button;
        }

        function routeStatus(message, error) {
            elRouteStatus.textContent = message;
            elRouteStatus.classList.toggle('text-danger', !!error);
        }

        function clearRouteLine() {
            if (routeLayer && map) map.removeLayer(routeLayer);
            routeLayer = null;
            elRouteResult.hidden = true;
            document.getElementById('bkRouteSteps').textContent = '';
        }

        function cancelRequest() {
            routing.sequence++;
            if (routing.request) routing.request.abort();
            routing.request = null;
            document.getElementById('bkMap').setAttribute('aria-busy', 'false');
        }

        function pickingOrigin(enabled) {
            routing.picking = enabled;
            elRoutePick.classList.toggle('is-on', enabled);
            elRoutePick.setAttribute('aria-pressed', enabled ? 'true' : 'false');
            document.getElementById('bkMap').classList.toggle('is-picking-origin', enabled);
        }

        function setOrigin(point, label) {
            routing.origin = point;
            pickingOrigin(false);
            elOrigin.textContent = label;
            elRouteClear.hidden = false;
            if (map) {
                if (originMarker) map.removeLayer(originMarker);
                originMarker = L.circleMarker([point.lat, point.lng], {
                    radius: 9, color: '#fff', weight: 3, fillColor: '#ff3d71', fillOpacity: 1
                }).addTo(map).bindTooltip('Titik awal perjalanan');
            }
            if (routing.destination) calculateRoute();
            else routeStatus('Titik awal siap. Pilih tombol Rute pada bioskop tujuan.');
        }

        function locateOrigin() {
            if (!map) { routeStatus('Peta belum tersedia. Muat ulang halaman untuk mencoba lagi.', true); return; }
            cancelRequest();
            clearRouteLine();
            pickingOrigin(false);
            var locationSequence = ++routing.locationSequence;
            if (!navigator.geolocation || !window.isSecureContext) {
                pickingOrigin(true);
                routeStatus('Lokasi otomatis tidak tersedia. Klik peta untuk memilih titik awal.', true);
                return;
            }
            elRouteLocate.disabled = true;
            routeStatus('Mencari lokasi Anda… Jika tidak tersedia, pilih titik awal pada peta.');
            navigator.geolocation.getCurrentPosition(function (position) {
                if (locationSequence !== routing.locationSequence) return;
                elRouteLocate.disabled = false;
                setOrigin({ lat: position.coords.latitude, lng: position.coords.longitude }, 'Titik awal: lokasi Anda.');
            }, function (error) {
                if (locationSequence !== routing.locationSequence) return;
                elRouteLocate.disabled = false;
                pickingOrigin(true);
                routeStatus(error.code === 1
                    ? 'Izin lokasi ditolak. Klik peta untuk memilih titik awal perjalanan.'
                    : 'Lokasi belum ditemukan. Klik peta untuk memilih titik awal perjalanan.', true);
            }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
        }

        function beginRoute(cinema) {
            if (!map) { routeStatus('Peta gagal dimuat. Periksa koneksi lalu muat ulang halaman.', true); return; }
            cancelRequest();
            clearRouteLine();
            routing.destination = cinema;
            elDestination.textContent = cinema.name;
            elRouteClear.hidden = false;
            setActive(cinema.id, false);
            map.closePopup();
            if (markers[cinema.id] && !layer.hasLayer(markers[cinema.id])) layer.addLayer(markers[cinema.id]);
            document.querySelector('.cl-bk-mapwrap').scrollIntoView({ block: 'start', behavior: 'smooth' });
            if (routing.origin) calculateRoute();
            else if (state.user) setOrigin({ lat: state.user.lat, lng: state.user.lng }, 'Titik awal: lokasi Anda.');
            else locateOrigin();
        }

        function meters(value) {
            return value >= 1000 ? (value / 1000).toFixed(1).replace('.', ',') + ' km' : Math.round(value) + ' m';
        }

        function duration(seconds) {
            var minutes = Math.max(1, Math.ceil(seconds / 60));
            return minutes >= 60 ? Math.floor(minutes / 60) + ' jam' + (minutes % 60 ? ' ' + minutes % 60 + ' menit' : '') : minutes + ' menit';
        }

        async function calculateRoute() {
            if (!map || !routing.origin || !routing.destination) return;
            cancelRequest();
            clearRouteLine();
            var sequence = routing.sequence;
            var controller = new AbortController();
            routing.request = controller;
            document.getElementById('bkMap').setAttribute('aria-busy', 'true');
            routeStatus('Menghitung rute ke ' + routing.destination.name + '…');
            try {
                var response = await fetch(ROUTE_URL, {
                    method: 'POST', signal: controller.signal,
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': TOKEN },
                    body: JSON.stringify({ cinema_id: routing.destination.id, origin: routing.origin, profile: elTravel.value })
                });
                var data = await response.json().catch(function () { return {}; });
                if (sequence !== routing.sequence) return;
                if (!response.ok) {
                    if (response.status === 419) throw new Error('Sesi halaman berakhir. Muat ulang halaman lalu coba lagi.');
                    if (response.status === 429) throw new Error('Terlalu banyak permintaan rute. Tunggu sebentar lalu coba lagi.');
                    throw new Error(data.message || 'Rute belum dapat dihitung. Coba lagi.');
                }
                if (!data.geometry || data.geometry.type !== 'LineString') throw new Error('Data rute tidak lengkap. Coba lagi.');
                routeLayer = L.geoJSON({ type: 'Feature', properties: {}, geometry: data.geometry }, {
                    style: { color: '#ff8a00', weight: 6, opacity: 0.95 }
                }).addTo(map);
                document.getElementById('bkRouteDistance').textContent = meters(data.distance);
                document.getElementById('bkRouteDuration').textContent = duration(data.duration);
                var steps = document.getElementById('bkRouteSteps');
                (data.steps || []).forEach(function (step) {
                    var item = el('li');
                    item.appendChild(el('span', null, step.instruction));
                    if (step.distance > 0) item.appendChild(el('small', 'cl-sub', meters(step.distance)));
                    steps.appendChild(item);
                });
                elRouteResult.hidden = false;
                var provider = document.getElementById('bkRouteProvider');
                provider.textContent = data.provider === 'Valhalla' ? 'Valhalla / FOSSGIS' : 'OpenRouteService';
                provider.href = data.provider === 'Valhalla' ? 'https://valhalla.openstreetmap.de/' : 'https://openrouteservice.org/';
                pickingOrigin(false);
                map.fitBounds(routeLayer.getBounds().extend([routing.origin.lat, routing.origin.lng]).extend([routing.destination.lat, routing.destination.lng]), { padding: [35, 35], maxZoom: 16 });
                routeStatus('Rute ke ' + routing.destination.name + ' siap.' + (routing.destination.coord === 'estimasi' ? ' Koordinat bioskop masih berupa perkiraan.' : ''));
            } catch (error) {
                if (sequence !== routing.sequence || error.name === 'AbortError') return;
                clearRouteLine();
                routeStatus(error.message && error.message !== 'Failed to fetch' ? error.message : 'Koneksi terputus. Periksa internet lalu coba lagi.', true);
            } finally {
                if (sequence === routing.sequence) {
                    routing.request = null;
                    document.getElementById('bkMap').setAttribute('aria-busy', 'false');
                }
            }
        }

        elRouteLocate.addEventListener('click', locateOrigin);
        elRoutePick.addEventListener('click', function () {
            if (!map) { routeStatus('Peta belum tersedia. Muat ulang halaman untuk mencoba lagi.', true); return; }
            routing.locationSequence++;
            elRouteLocate.disabled = false;
            cancelRequest();
            clearRouteLine();
            pickingOrigin(!routing.picking);
            routeStatus(routing.picking ? 'Klik pada jalan di peta untuk menentukan titik awal perjalanan.' : 'Pemilihan titik awal dibatalkan.');
            if (routing.picking) {
                var center = routing.destination || { lat: 3.5896, lng: 98.6738 };
                map.setView([center.lat, center.lng], 13);
            }
        });
        elRouteClear.addEventListener('click', function () {
            cancelRequest();
            routing.locationSequence++;
            elRouteLocate.disabled = false;
            clearRouteLine();
            pickingOrigin(false);
            routing.destination = null;
            routing.origin = null;
            if (originMarker && map) map.removeLayer(originMarker);
            originMarker = null;
            elDestination.textContent = 'Pilih tombol Rute pada bioskop tujuan.';
            elOrigin.textContent = 'Titik awal belum dipilih.';
            elRouteClear.hidden = true;
            routeStatus('Rute dihapus. Pilih tujuan untuk membuat rute baru.');
            fitTo(visible());
        });
        elTravel.addEventListener('change', function () {
            document.getElementById('bkRoutePrivacy').textContent = 'Saat menghitung rute, koordinat titik awal dan bioskop dikirim ke ' + (elTravel.value === 'motorcycle' ? 'Valhalla / FOSSGIS.' : 'OpenRouteService.');
            if (!routing.picking) calculateRoute();
        });
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
            row.appendChild(routeButton(c));
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
            actions.appendChild(routeButton(c));
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
            if (fit !== false && !routeLayer) fitTo(list);
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
