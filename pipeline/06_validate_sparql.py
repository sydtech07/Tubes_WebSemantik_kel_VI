"""Langkah 6 - Uji validasi query SPARQL + infrastruktur Fuseki, hasilnya berupa tabel untuk paper.

Setiap uji kasus punya:  (1) query SPARQL, (2) hasil yang DIHARAPKAN yang dihitung langsung dari CSV
(tanpa memakai RDF), (3) hasil aktual dari triple store. Cocok = "Valid".

Dua mode:
  --endpoint URL   uji ke Fuseki sungguhan (juga menjalankan uji infrastruktur: ping, tambah & hapus triple)
  --ttl BERKAS     uji ke berkas Turtle secara lokal dengan rdflib (tanpa Fuseki)

Keluaran: docs/hasil_uji_sparql.md  (tabel siap tempel) dan docs/hasil_uji_sparql.json

Contoh:
  python pipeline/06_validate_sparql.py --endpoint http://localhost:3030/cinelog
  python pipeline/06_validate_sparql.py --ttl data/rdf/cinelog-data.ttl
"""
from __future__ import annotations

import argparse
import itertools
import json
import re
import sys
from collections import Counter, defaultdict
from datetime import date

import requests
from rdflib import Graph

from common import ROOT, USER_AGENT, setup_logging
from rdfcommon import BASE_URI, DATA_TTL, FUSEKI_URL, load_ontology, read_csv

log = setup_logging("validate")

PREFIXES = f"""PREFIX cin: <{BASE_URI}ontology#>
PREFIX schema: <https://schema.org/>
PREFIX owl: <http://www.w3.org/2002/07/owl#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
"""


def lit(text: str) -> str:
    """Escape string untuk literal SPARQL."""
    return text.replace("\\", "\\\\").replace('"', '\\"').replace("\n", " ")


# --------------------------------------------------------------------------- penjalan query
class Remote:
    kind = "endpoint"

    def __init__(self, url: str):
        self.url = url.rstrip("/")
        self.host = self.url.rsplit("/", 1)[0]
        self.session = requests.Session()
        self.session.headers["User-Agent"] = USER_AGENT

    def _post(self, names: list[str], data: dict, accept: str) -> requests.Response:
        r = None
        for n in names:
            r = self.session.post(self.url + n, data=data, headers={"Accept": accept}, timeout=120)
            if r.status_code != 404:
                break
        r.raise_for_status()
        return r

    def select(self, q: str) -> list[dict]:
        r = self._post(["/sparql", "/query", ""], {"query": q}, "application/sparql-results+json")
        return [{k: v["value"] for k, v in row.items()} for row in r.json()["results"]["bindings"]]

    def ask(self, q: str) -> bool:
        r = self._post(["/sparql", "/query", ""], {"query": q}, "application/sparql-results+json")
        return bool(r.json()["boolean"])

    def update(self, q: str) -> None:
        self._post(["/update", ""], {"update": q}, "*/*")

    def ping(self) -> bool:
        return self.session.get(f"{self.host}/$/ping", timeout=10).status_code == 200


class Local:
    kind = "berkas"

    def __init__(self, path: str):
        self.g = Graph()
        self.g.parse(path, format="turtle")
        self.g += load_ontology(BASE_URI)

    def select(self, q: str) -> list[dict]:
        res = self.g.query(q)
        return [{str(v): str(row[v]) for v in res.vars if row[v] is not None} for row in res]

    def ask(self, q: str) -> bool:
        return bool(self.g.query(q).askAnswer)

    def triple_count(self) -> int:
        return len(self.g)


# --------------------------------------------------------------------------- hitungan harapan dari CSV
def load_facts() -> dict:
    movies, genres = read_csv("movies.csv"), {r["tmdb_id"]: r["name"] for r in read_csv("genres.csv")}
    people = {r["tmdb_id"]: r["name"] for r in read_csv("people.csv")}
    credits = read_csv("movie_credits.csv")
    by_movie_genres: dict[str, set] = defaultdict(set)
    for r in read_csv("movie_genres.csv"):
        by_movie_genres[r["movie_tmdb_id"]].add(genres[r["genre_tmdb_id"]])
    return {"movies": movies, "genres": genres, "people": people, "credits": credits, "mg": by_movie_genres}


def build_cases(f: dict) -> list[dict]:
    movies, mg, people, credits = f["movies"], f["mg"], f["people"], f["credits"]
    cases: list[dict] = []

    def add(title, query, expected, check):
        cases.append({"title": title, "query": PREFIXES + query, "expected": expected, "check": check})

    n = lambda rows: int(rows[0]["n"])

    add("Hitung seluruh film",
        "SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m a cin:Movie }",
        len(movies), lambda rows, e: n(rows) == e)

    add("Hitung seluruh genre",
        "SELECT (COUNT(DISTINCT ?g) AS ?n) WHERE { ?g a cin:Genre }",
        len(f["genres"]), lambda rows, e: n(rows) == e)

    genre_counts = Counter(g for gs in mg.values() for g in gs)
    top_genre, top_genre_n = genre_counts.most_common(1)[0]
    add(f'Tampilkan film bergenre "{top_genre}" (genre paling banyak)',
        f'SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE {{ ?m schema:genre ?g . ?g rdfs:label "{lit(top_genre)}"@en }}',
        top_genre_n, lambda rows, e: n(rows) == e)

    add("Distribusi jumlah film untuk SEMUA genre",
        "SELECT ?genre (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:genre ?g . ?g rdfs:label ?genre } GROUP BY ?genre",
        dict(genre_counts),
        lambda rows, e: {r["genre"]: int(r["n"]) for r in rows} == e)

    months = Counter(m["release_date"][:7] for m in movies)
    add("Distribusi jumlah film untuk SEMUA bulan rilis",
        "SELECT ?bulan (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:datePublished ?d } "
        "GROUP BY (SUBSTR(STR(?d), 1, 7) AS ?bulan)",
        dict(months),
        lambda rows, e: {r["bulan"]: int(r["n"]) for r in rows} == e)

    month, month_n = months.most_common(1)[0]
    y, mo = int(month[:4]), int(month[5:])
    last_day = (date(y + (mo == 12), mo % 12 + 1, 1) - date(y, mo, 1)).days
    add(f"Tampilkan film yang rilis pada bulan {month}",
        f'SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE {{ ?m schema:datePublished ?d . '
        f'FILTER(?d >= "{month}-01"^^xsd:date && ?d <= "{month}-{last_day:02d}"^^xsd:date) }}',
        month_n, lambda rows, e: n(rows) == e)

    long_n = sum(1 for m in movies if m["runtime"] and float(m["runtime"]) > 150)
    add("Tampilkan film berdurasi lebih dari 150 menit",
        "SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m cin:runtimeMinutes ?r . FILTER(?r > 150) }",
        long_n, lambda rows, e: n(rows) == e)

    director_films: dict[str, set] = defaultdict(set)      # nama sutradara -> film
    for c in credits:
        if c["credit_type"] == "crew" and c["role"] == "Director":
            director_films[people[c["person_tmdb_id"]]].add(c["movie_tmdb_id"])
    d_name, d_films = max(director_films.items(), key=lambda kv: len(kv[1]))
    add(f'Tampilkan film yang disutradarai "{d_name}" (sutradara dengan film terbanyak)',
        f'SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE {{ ?m schema:director ?p . ?p schema:name "{lit(d_name)}" }}',
        len(d_films), lambda rows, e: n(rows) == e)

    top_movie = max(movies, key=lambda m: float(m["tmdb_popularity"] or 0))
    add("Film paling populer (urut skor popularitas menurun)",
        "SELECT ?title WHERE { ?m a cin:Movie ; schema:name ?title ; cin:popularity ?p } ORDER BY DESC(?p) LIMIT 1",
        top_movie["title"], lambda rows, e: rows and rows[0]["title"] == e)

    cast_rows = [c for c in credits if c["movie_tmdb_id"] == top_movie["tmdb_id"] and c["credit_type"] == "cast"]
    distinct_actors = len({c["person_tmdb_id"] for c in cast_rows})
    add(f'Hitung pemeran film "{top_movie["title"]}"',
        f'SELECT (COUNT(DISTINCT ?a) AS ?n) WHERE {{ ?m schema:name "{lit(top_movie["title"])}" ; schema:actor ?a }}',
        distinct_actors, lambda rows, e: n(rows) == e)

    add(f'Hitung peran (karakter) pada film "{top_movie["title"]}"',
        f'SELECT (COUNT(DISTINCT ?r) AS ?n) WHERE {{ ?m schema:name "{lit(top_movie["title"])}" ; '
        f'cin:hasCastRole ?r . ?r cin:characterName ?c ; cin:byActor ?a }}',
        len({(c["person_tmdb_id"], c["role"]) for c in cast_rows if c["role"]}),
        lambda rows, e: n(rows) == e)

    films_per_actor: dict[str, set] = defaultdict(set)
    for c in credits:
        if c["credit_type"] == "cast":
            films_per_actor[c["person_tmdb_id"]].add(c["movie_tmdb_id"])
    best = max(len(v) for v in films_per_actor.values())
    best_names = {people[p] for p, v in films_per_actor.items() if len(v) == best}
    add("Aktor yang tampil di film terbanyak",
        "SELECT ?name (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m schema:actor ?a . ?a schema:name ?name } "
        "GROUP BY ?a ?name ORDER BY DESC(?n) LIMIT 1",
        (best, best_names), lambda rows, e: int(rows[0]["n"]) == e[0] and rows[0]["name"] in e[1])

    pair_counts: Counter = Counter()
    for gs in mg.values():
        for a, b in itertools.combinations(sorted(gs), 2):
            pair_counts[(a, b)] += 1
    if pair_counts:
        (ga, gb), pair_n = pair_counts.most_common(1)[0]
        add(f'Tampilkan film bergenre "{ga}" sekaligus "{gb}" (kombinasi paling umum)',
            f'SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE {{ ?m schema:genre ?g1 , ?g2 . '
            f'?g1 rdfs:label "{lit(ga)}"@en . ?g2 rdfs:label "{lit(gb)}"@en }}',
            pair_n, lambda rows, e: n(rows) == e)

    linked = sum(1 for m in movies if re.fullmatch(r"Q\d+", m["wikidata_id"] or ""))
    add("Hitung film yang tertaut ke Wikidata (owl:sameAs)",
        "SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m a cin:Movie ; "
        "owl:sameAs ?w . FILTER(STRSTARTS(STR(?w), \"http://www.wikidata.org/entity/Q\")) }",
        linked, lambda rows, e: n(rows) == e)

    add("Hitung film yang BELUM tertaut ke Wikidata",
        "SELECT (COUNT(DISTINCT ?m) AS ?n) WHERE { ?m a cin:Movie . FILTER NOT EXISTS { ?m owl:sameAs ?w } }",
        len(movies) - linked, lambda rows, e: n(rows) == e)

    return cases


# --------------------------------------------------------------------------- uji infrastruktur Fuseki
def infra_cases(remote: Remote, expected_triples: int) -> list[dict]:
    out = []
    graph = "urn:cinelog:test"

    def record(aspect, test, ok, detail=""):
        out.append({"aspect": aspect, "test": test, "status": "Berhasil" if ok else "Gagal", "detail": detail})

    try:
        record("Infrastruktur", "Menjalankan web server (ping)", remote.ping())
    except requests.RequestException as exc:
        record("Infrastruktur", "Menjalankan web server (ping)", False, str(exc))
        return out
    try:
        remote.update(f'INSERT DATA {{ GRAPH <{graph}> {{ <urn:cinelog:test:a> <urn:cinelog:test:p> "uji" }} }}')
        added = remote.ask(f'ASK {{ GRAPH <{graph}> {{ <urn:cinelog:test:a> <urn:cinelog:test:p> "uji" }} }}')
        record("Operasi triple store", "Penambahan triple", added)
        remote.update(f"DROP SILENT GRAPH <{graph}>")
        gone = not remote.ask(f'ASK {{ GRAPH <{graph}> {{ <urn:cinelog:test:a> ?p ?o }} }}')
        record("Operasi triple store", "Penghapusan triple", gone)
        rows = remote.select("SELECT (COUNT(*) AS ?n) WHERE { ?s ?p ?o }")
        total = int(rows[0]["n"])
        record("Operasi query", "Melakukan query (hitung seluruh triple)", total > 0, f"{total} triple")
        record("Validasi query", "Jumlah triple di Fuseki = jumlah triple berkas lokal",
               total == expected_triples, f"Fuseki {total} vs lokal {expected_triples}")
    except requests.RequestException as exc:
        record("Operasi triple store", "Tambah/hapus triple", False, f"{exc} (apakah Fuseki dijalankan dengan --update?)")
    return out


# --------------------------------------------------------------------------- laporan
def one_line(q: str) -> str:
    body = q.split("\n", PREFIXES.count("\n"))[-1] if q.startswith(PREFIXES) else q
    return re.sub(r"\s+", " ", body).strip().replace("|", "\\|")


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    mode = ap.add_mutually_exclusive_group()
    mode.add_argument("--endpoint", help=f"alamat dataset Fuseki, mis. {FUSEKI_URL}")
    mode.add_argument("--ttl", help="berkas Turtle untuk uji lokal dengan rdflib")
    args = ap.parse_args()
    if not args.endpoint and not args.ttl:
        args.ttl = str(DATA_TTL)

    facts = load_facts()
    cases = build_cases(facts)
    if args.endpoint:
        engine = Remote(args.endpoint)
        local = Graph(); local.parse(str(DATA_TTL), format="turtle"); local += load_ontology(BASE_URI)
        infra = infra_cases(engine, len(local))
    else:
        engine, infra = Local(args.ttl), []

    results = []
    for i, c in enumerate(cases, 1):
        try:
            rows = engine.select(c["query"])
            ok = bool(c["check"](rows, c["expected"]))
            if not rows:
                actual = "(kosong)"
            elif len(rows) > 1:
                actual = f"{len(rows)} baris hasil"
            else:
                actual = "; ".join(rows[0].values()) if len(rows[0]) == 1 else \
                    "; ".join(f"{k}={v}" for k, v in rows[0].items())
        except Exception as exc:                      # kesalahan query dianggap tidak valid, tetap dilaporkan
            ok, actual = False, f"ERROR: {exc}"
        results.append({"no": i, "uji_kasus": c["title"], "query": one_line(c["query"]),
                        "diharapkan": str(c["expected"])[:160], "aktual": str(actual)[:160], "valid": ok})
        log.info("[%s] %s", "Valid" if ok else "TIDAK VALID", c["title"])

    docs = ROOT / "docs"
    docs.mkdir(exist_ok=True)
    lines = [f"# Hasil uji validasi SPARQL (mode {engine.kind})", "",
             "## Tabel A. Hasil pengujian uji kasus menggunakan query SPARQL", "",
             "| No | Uji Kasus | Statement Kueri SPARQL | Hasil diharapkan (dari CSV) | Hasil aktual | Status |",
             "|---|---|---|---|---|---|"]
    for r in results:
        lines.append(f"| {r['no']} | {r['uji_kasus']} | `{r['query']}` | {r['diharapkan']} | {r['aktual']} | "
                     f"{'Valid' if r['valid'] else 'Tidak valid'} |")
    if infra:
        lines += ["", "## Tabel B. Hasil pengujian prototipe web server Jena Fuseki", "",
                  "| No | Aspek Pengujian | Test Case | Status | Keterangan |", "|---|---|---|---|---|"]
        for i, r in enumerate(infra, 1):
            lines.append(f"| {i} | {r['aspect']} | {r['test']} | {r['status']} | {r['detail']} |")
    (docs / "hasil_uji_sparql.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    (docs / "hasil_uji_sparql.json").write_text(
        json.dumps({"query": results, "infrastruktur": infra}, ensure_ascii=False, indent=2), encoding="utf-8")

    bad = [r for r in results if not r["valid"]] + [r for r in infra if r["status"] != "Berhasil"]
    log.info("Ringkasan: %d/%d uji kasus valid%s. Tabel: %s", sum(r["valid"] for r in results), len(results),
             f", infrastruktur {sum(r['status'] == 'Berhasil' for r in infra)}/{len(infra)} berhasil" if infra else "",
             docs / "hasil_uji_sparql.md")
    sys.exit(1 if bad else 0)


if __name__ == "__main__":
    main()
