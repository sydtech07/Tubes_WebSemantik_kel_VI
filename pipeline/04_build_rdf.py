"""Langkah 4 - Ubah CSV bersih menjadi dataset RDF (Turtle) berdasarkan ontologi CINÉLOG.

Masukan : data/clean/*.csv (hasil 02_clean.py), direktori bioskop JSON, dan CSV auditorium/jadwal
Keluaran: data/rdf/cinelog-data.ttl   dataset RDF buatan sendiri
          data/rdf/rdf_report.json    ringkasan jumlah triple (bahan paper)
          data/rdf/cinelog-data.rdf   RDF/XML (hanya dengan --rdfxml)

Model data:
  Film   -> schema:name, schema:datePublished, schema:duration, schema:description (@en/@id),
            schema:genre, schema:actor, schema:director, schema:author, schema:musicBy, schema:image,
            owl:sameAs (Wikidata), schema:sameAs (IMDb, TMDB), serta properti cin:* dari ontologi sendiri
  Peran  -> cin:CastRole (film + aktor + nama karakter + urutan tampil)
  Orang  -> schema:name, schema:gender, schema:image, cin:knownForDepartment
  Genre  -> skos:prefLabel / rdfs:label (@en)
  Cinema -> schema:MovieTheater, schema:geo -> cin:GeoCoordinates
  Auditorium -> cin:partOfCinema -> Cinema
  Screening -> schema:ScreeningEvent, schema:workPresented -> Movie, cin:heldAtCinema -> Cinema

Contoh:
  python pipeline/04_build_rdf.py
  python pipeline/04_build_rdf.py --base http://localhost:8000/ --rdfxml
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from collections import Counter
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation

from rdflib import Graph, Literal, URIRef
from rdflib.namespace import DCTERMS, OWL, RDF, RDFS, SKOS, VOID, XSD

from common import setup_logging
from rdfcommon import (BASE_URI, DATA_TTL, RDF_DIR, SCHEMA, TMDB_IMG, WD_ENTITY, cin_ns,
                       read_csv, read_optional_csv, read_cinemas)

log = setup_logging("rdf")

CREW_PROPERTY = {
    "Director": SCHEMA.director,
    "Screenplay": SCHEMA.author,
    "Writer": SCHEMA.author,
    "Original Music Composer": SCHEMA.musicBy,
}
URL_OK = re.compile(r"^https?://[^\s<>\"{}|\\^`]+$")
QID_OK = re.compile(r"^Q\d+$")


def res(base: str, kind: str, ident) -> URIRef:
    return URIRef(f"{base}resource/{kind}/{ident}")


def dec(value: str) -> Literal | None:
    try:
        return Literal(Decimal(value), datatype=XSD.decimal)
    except (InvalidOperation, ValueError):
        return None


def add_if(g: Graph, s, p, o) -> None:
    if o is not None:
        g.add((s, p, o))


def decimal_value(value: str) -> Decimal | None:
    try:
        return Decimal(str(value).strip())
    except (InvalidOperation, ValueError, TypeError):
        return None


def valid_resource_id(value: str) -> bool:
    return bool(value and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*", value.strip()))


def parse_datetime(value: str) -> datetime | None:
    """Terima ISO-8601 dengan zona waktu eksplisit agar jam tayang tidak ambigu."""
    try:
        parsed = datetime.fromisoformat(value.strip().replace("Z", "+00:00"))
    except (ValueError, AttributeError):
        return None
    return parsed if parsed.tzinfo is not None and parsed.utcoffset() is not None else None


def build_graph(base: str) -> tuple[Graph, Counter]:
    CIN = cin_ns(base)
    movies, genres = read_csv("movies.csv"), read_csv("genres.csv")
    movie_genres, people = read_csv("movie_genres.csv"), read_csv("people.csv")
    credits = read_csv("movie_credits.csv")
    movie_keywords = read_csv("movie_keywords.csv")
    cinemas = read_cinemas()
    auditoriums = read_optional_csv("auditoriums.csv")
    screenings = read_optional_csv("screenings.csv")

    g = Graph()
    for prefix, ns in (("cin", CIN), ("schema", SCHEMA), ("owl", OWL), ("rdfs", RDFS), ("skos", SKOS),
                       ("dct", DCTERMS), ("void", VOID), ("xsd", XSD)):
        g.bind(prefix, ns)
    skipped: Counter = Counter()

    # ---- Genre ----
    for row in genres:
        s = res(base, "genre", row["tmdb_id"])
        g.add((s, RDF.type, CIN.Genre))
        g.add((s, RDF.type, SKOS.Concept))
        g.add((s, SKOS.prefLabel, Literal(row["name"], lang="en")))
        g.add((s, RDFS.label, Literal(row["name"], lang="en")))
        g.add((s, CIN.tmdbId, Literal(int(row["tmdb_id"]))))

    # ---- Orang ----
    person_ids = set()
    for row in people:
        pid = row["tmdb_id"]
        person_ids.add(pid)
        s = res(base, "person", pid)
        g.add((s, RDF.type, CIN.Person))
        g.add((s, RDF.type, SCHEMA.Person))
        g.add((s, SCHEMA.name, Literal(row["name"])))
        g.add((s, RDFS.label, Literal(row["name"])))
        g.add((s, CIN.tmdbId, Literal(int(pid))))
        g.add((s, SCHEMA.sameAs, URIRef(f"https://www.themoviedb.org/person/{pid}")))
        if row["gender"] == "1":
            g.add((s, SCHEMA.gender, SCHEMA.Female))
        elif row["gender"] == "2":
            g.add((s, SCHEMA.gender, SCHEMA.Male))
        if row["known_for_department"]:
            g.add((s, CIN.knownForDepartment, Literal(row["known_for_department"])))
        if row["profile_path"]:
            g.add((s, SCHEMA.image, URIRef(f"{TMDB_IMG}w185{row['profile_path']}")))

    # ---- Film ----
    movie_ids = set()
    for m in movies:
        mid = m["tmdb_id"]
        movie_ids.add(mid)
        s = res(base, "movie", mid)
        g.add((s, RDF.type, CIN.Movie))
        g.add((s, RDF.type, SCHEMA.Movie))
        g.add((s, SCHEMA.name, Literal(m["title"])))
        g.add((s, RDFS.label, Literal(m["title"])))
        if m["original_title"] and m["original_title"] != m["title"]:
            g.add((s, SCHEMA.alternateName, Literal(m["original_title"])))
        g.add((s, SCHEMA.datePublished, Literal(m["release_date"], datatype=XSD.date)))
        g.add((s, SCHEMA.url, URIRef(f"{base}film/{m['slug']}")))
        g.add((s, CIN.tmdbId, Literal(int(mid))))
        if m["runtime"]:
            g.add((s, CIN.runtimeMinutes, Literal(int(float(m["runtime"])))))
            g.add((s, SCHEMA.duration, Literal(f"PT{int(float(m['runtime']))}M", datatype=XSD.duration)))
        if m["overview_en"]:
            g.add((s, SCHEMA.description, Literal(m["overview_en"], lang="en")))
        if m["overview_id"]:
            g.add((s, SCHEMA.description, Literal(m["overview_id"], lang="id")))
        if m["tagline"]:
            g.add((s, SCHEMA.slogan, Literal(m["tagline"])))
        if m["original_language"]:
            g.add((s, CIN.originalLanguage, Literal(m["original_language"])))
            g.add((s, SCHEMA.inLanguage, Literal(m["original_language"])))
        if m["poster_path"]:
            g.add((s, SCHEMA.image, URIRef(f"{TMDB_IMG}w500{m['poster_path']}")))
        if m["backdrop_path"]:
            g.add((s, CIN.backdropImage, URIRef(f"{TMDB_IMG}w1280{m['backdrop_path']}")))
        if m["homepage"] and URL_OK.match(m["homepage"]):
            g.add((s, SCHEMA.sameAs, URIRef(m["homepage"])))
        for key, prop in (("tmdb_popularity", CIN.popularity), ("tmdb_vote_average", CIN.tmdbVoteAverage)):
            if m[key]:
                add_if(g, s, prop, dec(m[key]))
        if m["tmdb_vote_count"]:
            g.add((s, CIN.tmdbVoteCount, Literal(int(float(m["tmdb_vote_count"])))))
        if m["budget"]:
            g.add((s, CIN.budget, Literal(int(float(m["budget"])))))
        if m["revenue"]:
            g.add((s, CIN.revenue, Literal(int(float(m["revenue"])))))
        # Tautan ke dataset eksternal
        g.add((s, SCHEMA.sameAs, URIRef(f"https://www.themoviedb.org/movie/{mid}")))
        if m["imdb_id"]:
            g.add((s, CIN.imdbId, Literal(m["imdb_id"])))
            g.add((s, SCHEMA.sameAs, URIRef(f"https://www.imdb.com/title/{m['imdb_id']}/")))
        if QID_OK.match(m["wikidata_id"] or ""):
            g.add((s, OWL.sameAs, URIRef(WD_ENTITY + m["wikidata_id"])))

    # ---- Film <-> Genre ----
    genre_ids = {r["tmdb_id"] for r in genres}
    for r in movie_genres:
        if r["movie_tmdb_id"] in movie_ids and r["genre_tmdb_id"] in genre_ids:
            g.add((res(base, "movie", r["movie_tmdb_id"]), SCHEMA.genre, res(base, "genre", r["genre_tmdb_id"])))
        else:
            skipped["relasi_genre_tanpa_induk"] += 1

    # ---- Keyword (tema film) dan Film <-> Keyword ----
    keyword_seen = set()
    for r in movie_keywords:
        if r["movie_tmdb_id"] not in movie_ids:
            skipped["relasi_keyword_tanpa_induk"] += 1
            continue
        kid = r["keyword_tmdb_id"]
        k = res(base, "keyword", kid)
        if kid not in keyword_seen:
            keyword_seen.add(kid)
            g.add((k, RDF.type, CIN.Keyword))
            g.add((k, RDF.type, SKOS.Concept))
            g.add((k, SKOS.prefLabel, Literal(r["keyword"], lang="en")))
            g.add((k, RDFS.label, Literal(r["keyword"], lang="en")))
            g.add((k, CIN.tmdbId, Literal(int(kid))))
        g.add((res(base, "movie", r["movie_tmdb_id"]), CIN.hasKeyword, k))

    # ---- Credit: pemeran & kru ----
    for c in credits:
        mid, pid = c["movie_tmdb_id"], c["person_tmdb_id"]
        if mid not in movie_ids or pid not in person_ids:
            skipped["credit_tanpa_induk"] += 1
            continue
        movie, person = res(base, "movie", mid), res(base, "person", pid)
        if c["credit_type"] == "cast":
            g.add((movie, SCHEMA.actor, person))
            digest = hashlib.md5(c["role"].encode("utf-8")).hexdigest()[:6]
            node = res(base, "cast", f"{mid}-{pid}-{digest}")
            g.add((node, RDF.type, CIN.CastRole))
            g.add((movie, CIN.hasCastRole, node))
            g.add((node, CIN.byActor, person))
            if c["role"]:
                g.add((node, CIN.characterName, Literal(c["role"])))
            if c["billing_order"]:
                g.add((node, CIN.billingOrder, Literal(int(float(c["billing_order"])))))
        else:
            prop = CREW_PROPERTY.get(c["role"])
            if prop is None:
                skipped["jabatan_kru_tidak_dikenal"] += 1
                continue
            g.add((movie, prop, person))

    # ---- Bioskop + GeoCoordinates ----
    cinema_ids: set[str] = set()
    for row in cinemas:
        cinema_id = str(row.get("id", "")).strip()
        name = str(row.get("name", "")).strip()
        if not valid_resource_id(cinema_id) or not name:
            skipped["bioskop_id_atau_nama_tidak_valid"] += 1
            continue
        if cinema_id in cinema_ids:
            skipped["bioskop_duplikat"] += 1
            continue

        cinema_ids.add(cinema_id)
        cinema = res(base, "cinema", cinema_id)
        g.add((cinema, RDF.type, CIN.Cinema))
        g.add((cinema, RDF.type, SCHEMA.MovieTheater))
        g.add((cinema, SCHEMA.name, Literal(name, lang="id")))
        g.add((cinema, RDFS.label, Literal(name, lang="id")))
        g.add((cinema, SCHEMA.identifier, Literal(cinema_id)))

        chain = str(row.get("chain", "")).strip()
        city = str(row.get("city", "")).strip()
        district = str(row.get("district", "")).strip()
        address = str(row.get("address", "")).strip()
        if chain:
            g.add((cinema, CIN.cinemaChain, Literal(chain)))
        if city:
            g.add((cinema, CIN.city, Literal(city)))
            g.add((cinema, SCHEMA.addressLocality, Literal(city, lang="id")))
        if district:
            g.add((cinema, CIN.district, Literal(district)))
        if address:
            g.add((cinema, SCHEMA.address, Literal(address, lang="id")))
        region = str(row.get("region", "")).strip()
        if region:
            g.add((cinema, SCHEMA.addressRegion, Literal(region, lang="id")))
        source = str(row.get("source", "")).strip()
        if source and URL_OK.match(source):
            g.add((cinema, DCTERMS.source, URIRef(source)))
        site = str(row.get("site", "")).strip()
        if site and URL_OK.match(site):
            g.add((cinema, SCHEMA.url, URIRef(site)))

        lat, lng = decimal_value(row.get("lat", "")), decimal_value(row.get("lng", ""))
        if (lat is not None and lng is not None and lat.is_finite() and lng.is_finite()
                and Decimal("-90") <= lat <= Decimal("90")
                and Decimal("-180") <= lng <= Decimal("180")):
            coordinates = res(base, "coordinates", cinema_id)
            g.add((coordinates, RDF.type, CIN.GeoCoordinates))
            g.add((coordinates, RDF.type, SCHEMA.GeoCoordinates))
            g.add((coordinates, SCHEMA.latitude, Literal(lat, datatype=XSD.decimal)))
            g.add((coordinates, SCHEMA.longitude, Literal(lng, datatype=XSD.decimal)))
            g.add((coordinates, SCHEMA.addressCountry, Literal("ID")))
            coord_source = str(row.get("coord", "")).strip()
            if coord_source:
                # Nilai "estimasi" dipertahankan agar konsumen peta tahu koordinatnya perkiraan.
                g.add((coordinates, CIN.coordinateSource, Literal(coord_source)))
            g.add((cinema, CIN.hasGeoCoordinates, coordinates))
            g.add((cinema, SCHEMA.geo, coordinates))
        else:
            skipped["koordinat_bioskop_tidak_valid"] += 1


    # ---- Auditorium ----
    auditorium_ids: dict[str, str] = {}
    for row in auditoriums:
        auditorium_id = str(row.get("auditorium_id", "")).strip()
        cinema_id = str(row.get("cinema_id", "")).strip()
        name = str(row.get("name", "")).strip()
        if (not valid_resource_id(auditorium_id) or not name or cinema_id not in cinema_ids
                or auditorium_id in auditorium_ids):
            skipped["auditorium_id_nama_atau_bioskop_tidak_valid"] += 1
            continue

        auditorium_ids[auditorium_id] = cinema_id
        auditorium = res(base, "auditorium", auditorium_id)
        cinema = res(base, "cinema", cinema_id)
        g.add((auditorium, RDF.type, CIN.Auditorium))
        g.add((auditorium, RDF.type, SCHEMA.Place))
        g.add((auditorium, SCHEMA.name, Literal(name, lang="id")))
        g.add((auditorium, RDFS.label, Literal(name, lang="id")))
        g.add((auditorium, CIN.partOfCinema, cinema))
        g.add((auditorium, SCHEMA.containedInPlace, cinema))
        g.add((cinema, CIN.hasAuditorium, auditorium))

        screen_number = str(row.get("screen_number", "")).strip()
        if screen_number:
            try:
                number = int(screen_number)
                if number > 0:
                    g.add((auditorium, CIN.screenNumber, Literal(number, datatype=XSD.positiveInteger)))
                else:
                    skipped["nomor_layar_tidak_valid"] += 1
            except ValueError:
                skipped["nomor_layar_tidak_valid"] += 1

        capacity = str(row.get("capacity", "")).strip()
        if capacity:
            try:
                value = int(capacity)
                if value > 0:
                    g.add((auditorium, SCHEMA.maximumAttendeeCapacity, Literal(value, datatype=XSD.integer)))
                else:
                    skipped["kapasitas_auditorium_tidak_valid"] += 1
            except ValueError:
                skipped["kapasitas_auditorium_tidak_valid"] += 1

        auditorium_format = str(row.get("format", "")).strip()
        if auditorium_format:
            g.add((auditorium, CIN.auditoriumFormat, Literal(auditorium_format)))

    # ---- ScreeningEvent ----
    movie_ids = {str(m["tmdb_id"]) for m in movies}
    screening_ids: set[str] = set()
    for row in screenings:
        screening_id = str(row.get("screening_id", "")).strip()
        movie_id = str(row.get("movie_tmdb_id", "")).strip()
        cinema_id = str(row.get("cinema_id", "")).strip()
        auditorium_id = str(row.get("auditorium_id", "")).strip()
        start_text = str(row.get("start_datetime", "")).strip()

        if (not valid_resource_id(screening_id) or screening_id in screening_ids
                or movie_id not in movie_ids or cinema_id not in cinema_ids):
            skipped["jadwal_id_film_atau_bioskop_tidak_valid"] += 1
            continue
        start = parse_datetime(start_text)
        if start is None:
            skipped["jadwal_start_datetime_tidak_valid_atau_tanpa_timezone"] += 1
            continue
        end_text = str(row.get("end_datetime", "")).strip()
        end = parse_datetime(end_text) if end_text else None
        if end_text and (end is None or end <= start):
            skipped["jadwal_end_datetime_tidak_valid"] += 1
            continue
        if auditorium_id and (auditorium_id not in auditorium_ids or auditorium_ids[auditorium_id] != cinema_id):
            skipped["jadwal_auditorium_tidak_cocok_dengan_bioskop"] += 1
            continue

        screening_ids.add(screening_id)
        event = res(base, "screening", screening_id)
        movie = res(base, "movie", movie_id)
        cinema = res(base, "cinema", cinema_id)
        g.add((event, RDF.type, CIN.ScreeningEvent))
        g.add((event, RDF.type, SCHEMA.ScreeningEvent))
        g.add((event, SCHEMA.workPresented, movie))
        g.add((event, SCHEMA.location, cinema))
        g.add((event, CIN.heldAtCinema, cinema))
        g.add((cinema, CIN.hostsScreening, event))
        g.add((event, SCHEMA.startDate, Literal(start.isoformat(), datatype=XSD.dateTime)))
        if end is not None:
            g.add((event, SCHEMA.endDate, Literal(end.isoformat(), datatype=XSD.dateTime)))
        if auditorium_id:
            auditorium = res(base, "auditorium", auditorium_id)
            g.add((event, CIN.screenedInAuditorium, auditorium))
            g.add((event, SCHEMA.location, auditorium))

        video_format = str(row.get("video_format", "")).strip()
        subtitle_language = str(row.get("subtitle_language", "")).strip()
        status = str(row.get("status", "")).strip()
        if video_format:
            g.add((event, SCHEMA.videoFormat, Literal(video_format)))
        if subtitle_language:
            g.add((event, SCHEMA.subtitleLanguage, Literal(subtitle_language)))
        if status:
            g.add((event, CIN.screeningStatus, Literal(status)))

        price_text = str(row.get("price", "")).strip()
        currency = str(row.get("currency", "")).strip().upper()
        if price_text:
            price = decimal_value(price_text)
            if price is not None and price.is_finite() and price >= 0 and re.fullmatch(r"[A-Z]{3}", currency or ""):
                offer = res(base, "offer", screening_id)
                g.add((offer, RDF.type, SCHEMA.Offer))
                g.add((offer, SCHEMA.price, Literal(price, datatype=XSD.decimal)))
                g.add((offer, SCHEMA.priceCurrency, Literal(currency)))
                ticket_url = str(row.get("ticket_url", "")).strip()
                if ticket_url and URL_OK.match(ticket_url):
                    g.add((offer, SCHEMA.url, URIRef(ticket_url)))
                g.add((event, SCHEMA.offers, offer))
            else:
                skipped["harga_atau_kode_mata_uang_tidak_valid"] += 1

        source_url = str(row.get("source_url", "")).strip()
        if source_url and URL_OK.match(source_url):
            g.add((event, DCTERMS.source, URIRef(source_url)))

    # ---- Metadata dataset ----
    ds = URIRef(f"{base}dataset/cinelog")
    g.add((ds, RDF.type, VOID.Dataset))
    g.add((ds, DCTERMS.title, Literal("Dataset film dan bioskop CINÉLOG 2026", lang="id")))
    g.add((ds, DCTERMS.description, Literal(
        "Film, genre, orang, kru/pemeran dari TMDB; direktori bioskop dan koordinat dari data bioskop CINÉLOG; "
        "auditorium dan jadwal tayang dimuat dari CSV terpisah jika data terverifikasi tersedia.", lang="id")))
    g.add((ds, DCTERMS.source, URIRef("https://www.themoviedb.org/")))
    g.add((ds, DCTERMS.created, Literal(datetime.now(timezone.utc).date().isoformat(), datatype=XSD.date)))
    g.add((ds, DCTERMS.rights, Literal(
        "This product uses the TMDB API but is not endorsed or certified by TMDB.")))
    return g, skipped


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--base", default=BASE_URI, help=f"alamat dasar IRI (default {BASE_URI})")
    ap.add_argument("--rdfxml", action="store_true", help="tulis juga versi RDF/XML")
    args = ap.parse_args()
    base = args.base if args.base.endswith("/") else args.base + "/"

    g, skipped = build_graph(base)
    CIN = cin_ns(base)
    count_class = lambda cls: len(set(g.subjects(RDF.type, cls)))
    report = {
        "base_uri": base,
        "jumlah_triple": len(g),
        "film": count_class(CIN.Movie),
        "orang": count_class(CIN.Person),
        "genre": count_class(CIN.Genre),
        "keyword": count_class(CIN.Keyword),
        "peran_pemeran": count_class(CIN.CastRole),
        "bioskop": count_class(CIN.Cinema),
        "koordinat_bioskop": count_class(CIN.GeoCoordinates),
        "auditorium": count_class(CIN.Auditorium),
        "jadwal_tayang": count_class(CIN.ScreeningEvent),
        "film_tertaut_wikidata": len(set(g.subjects(OWL.sameAs, None))),
        "dilewati": dict(skipped),
    }
    g.serialize(destination=str(DATA_TTL), format="turtle")
    # rdflib dapat menulis baris kosong tambahan di EOF; rapikan agar diff Git bersih.
    DATA_TTL.write_text(DATA_TTL.read_text(encoding="utf-8").rstrip() + "\n", encoding="utf-8")
    if args.rdfxml:
        g.serialize(destination=str(RDF_DIR / "cinelog-data.rdf"), format="xml")
    (RDF_DIR / "rdf_report.json").write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    log.info("Dataset RDF ditulis ke %s\n%s", DATA_TTL, json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
