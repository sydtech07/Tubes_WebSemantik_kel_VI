"""Langkah 4 - Ubah CSV bersih menjadi dataset RDF (Turtle) berdasarkan ontologi CINÉLOG.

Masukan : data/clean/*.csv  (hasil 02_clean.py)
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
from rdfcommon import (BASE_URI, DATA_TTL, RDF_DIR, SCHEMA, TMDB_IMG, WD_ENTITY, cin_ns, read_csv)

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


def build_graph(base: str) -> tuple[Graph, Counter]:
    CIN = cin_ns(base)
    movies, genres = read_csv("movies.csv"), read_csv("genres.csv")
    movie_genres, people = read_csv("movie_genres.csv"), read_csv("people.csv")
    credits = read_csv("movie_credits.csv")

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

    # ---- Metadata dataset ----
    ds = URIRef(f"{base}dataset/cinelog")
    g.add((ds, RDF.type, VOID.Dataset))
    g.add((ds, DCTERMS.title, Literal("Dataset film CINÉLOG 2026", lang="id")))
    g.add((ds, DCTERMS.description, Literal(
        "Film rilis Januari-September 2026 dengan genre, pemeran, dan kru. Dibuat dari TMDB API.", lang="id")))
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
        "peran_pemeran": count_class(CIN.CastRole),
        "film_tertaut_wikidata": len(set(g.subjects(OWL.sameAs, None))),
        "dilewati": dict(skipped),
    }
    g.serialize(destination=str(DATA_TTL), format="turtle")
    if args.rdfxml:
        g.serialize(destination=str(RDF_DIR / "cinelog-data.rdf"), format="xml")
    (RDF_DIR / "rdf_report.json").write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    log.info("Dataset RDF ditulis ke %s\n%s", DATA_TTL, json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
