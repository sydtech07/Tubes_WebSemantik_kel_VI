"""Langkah 5 - Muat dataset RDF + ontologi ke Apache Jena Fuseki (Graph Store Protocol).

Idempoten: data lama di graph default DIGANTI (HTTP PUT), lalu ontologi ditambahkan (HTTP POST).
Menjalankan berulang kali menghasilkan isi yang sama, tanpa duplikasi.

Prasyarat: Fuseki berjalan (docker compose up -d) dan dataset /cinelog dibuat dengan --update.
Contoh:
  python pipeline/05_load_fuseki.py
  python pipeline/05_load_fuseki.py --url http://localhost:3030/cinelog
"""
from __future__ import annotations

import argparse
import sys

import requests
from rdflib import Graph

from common import USER_AGENT, setup_logging
from rdfcommon import DATA_TTL, FUSEKI_URL, ONTOLOGY_FILE, DEFAULT_BASE, BASE_URI, load_ontology

log = setup_logging("fuseki")
TURTLE = {"Content-Type": "text/turtle; charset=utf-8", "User-Agent": USER_AGENT}


def fail(msg: str) -> None:
    log.error(msg)
    sys.exit(1)


def endpoint_candidates(url: str, kind: str) -> list[str]:
    """Fuseki biasa menyediakan /data, /sparql, /update di bawah dataset; dataset URL sendiri juga diterima."""
    names = {"data": ["/data", ""], "query": ["/sparql", "/query", ""]}[kind]
    return [url + n for n in names]


def count_triples(url: str) -> int:
    q = "SELECT (COUNT(*) AS ?n) WHERE { ?s ?p ?o }"
    for ep in endpoint_candidates(url, "query"):
        r = requests.post(ep, data={"query": q}, headers={"Accept": "application/sparql-results+json",
                                                         "User-Agent": USER_AGENT}, timeout=120)
        if r.status_code == 200:
            return int(r.json()["results"]["bindings"][0]["n"]["value"])
    fail(f"Query hitung triple gagal pada semua endpoint (status terakhir {r.status_code}): {r.text[:200]}")
    return -1


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--url", default=FUSEKI_URL, help=f"alamat dataset Fuseki (default {FUSEKI_URL})")
    ap.add_argument("--file", default=str(DATA_TTL), help="berkas Turtle data (default data/rdf/cinelog-data.ttl)")
    args = ap.parse_args()
    url = args.url.rstrip("/")
    host = url.rsplit("/", 1)[0]

    if not DATA_TTL.exists() and args.file == str(DATA_TTL):
        fail(f"{DATA_TTL} belum ada. Jalankan 04_build_rdf.py dulu.")

    # 1. Fuseki hidup?
    try:
        r = requests.get(f"{host}/$/ping", timeout=10, headers={"User-Agent": USER_AGENT})
        if r.status_code != 200:
            fail(f"Fuseki menjawab status {r.status_code} pada {host}/$/ping")
    except requests.RequestException as exc:
        fail(f"Tidak bisa menghubungi Fuseki di {host}: {exc}\nPastikan 'docker compose ps' menunjukkan fuseki berjalan.")
    log.info("Fuseki aktif di %s", host)

    # 2. Ganti isi graph default dengan data baru (PUT)
    with open(args.file, "rb") as fh:
        payload = fh.read()
    log.info("Mengunggah %s (%.1f MB) ...", args.file, len(payload) / 1e6)
    attempts = []
    for ep in endpoint_candidates(url, "data"):
        resp = requests.put(ep + "?default", data=payload, headers=TURTLE, timeout=600)
        if resp.status_code in (200, 201, 204):
            log.info("Data dimuat lewat %s (HTTP %s)", ep, resp.status_code)
            break
        attempts.append(f"  PUT {ep}?default -> HTTP {resp.status_code} {resp.text[:150].strip()}")
    else:
        fail("Unggah data gagal. Percobaan:\n" + "\n".join(attempts) +
             "\nPastikan Fuseki dijalankan dengan opsi --update dan alamat dataset benar.")

    # 3. Tambahkan ontologi (POST), alamat dasarnya disesuaikan BASE_URI
    onto = load_ontology(BASE_URI).serialize(format="turtle").encode("utf-8")
    r = requests.post(ep + "?default", data=onto, headers=TURTLE, timeout=120)
    if r.status_code not in (200, 201, 204):
        fail(f"Unggah ontologi gagal (HTTP {r.status_code}): {r.text[:300]}")
    log.info("Ontologi ditambahkan (%s).", ONTOLOGY_FILE.name)

    # 4. Verifikasi jumlah triple: harus sama dengan gabungan berkas lokal
    local = Graph()
    local.parse(args.file, format="turtle")
    local += load_ontology(BASE_URI)
    remote = count_triples(url)
    log.info("Triple lokal=%d | triple di Fuseki=%d", len(local), remote)
    if remote != len(local):
        log.warning("Jumlah triple berbeda. Periksa apakah ada graph lain di dataset atau unggahan sebagian.")
    else:
        log.info("Verifikasi berhasil: jumlah triple cocok.")


if __name__ == "__main__":
    main()
