"""Validasi RDFa sebuah halaman CINÉLOG: ambil triple dari HTML, lalu cek satu per satu apakah ada di Fuseki.

Ini bukti bahwa data yang dilihat mesin pencari / agen semantik di halaman web SAMA dengan isi triple store.
Triple dengan blank node (mis. aggregateRating, data rating yang berubah-ubah) tidak ada di RDF dan dilewati.

Pemakaian (venv aktif, Fuseki dan aplikasi berjalan):
    python scripts\\validate_rdfa.py http://localhost:8000/film/NAMA-SLUG
    python scripts\\validate_rdfa.py http://localhost:8000/orang/5016
    python scripts\\validate_rdfa.py http://localhost:8000/genre/27 --show
    python scripts\\validate_rdfa.py berkas.html --base http://localhost:8000/film/NAMA-SLUG
"""
from __future__ import annotations

import argparse
import io
import sys
from pathlib import Path

import requests
from pyRdfa import pyRdfa, Options
from rdflib import BNode

RESOURCE_PREFIX = "http://localhost:8000/resource/"


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("source", help="URL halaman atau berkas .html")
    ap.add_argument("--base", help="alamat dasar (wajib bila source berupa berkas)")
    ap.add_argument("--fuseki", default="http://localhost:3030/cinelog", help="alamat dataset Fuseki")
    ap.add_argument("--resource-prefix", default=RESOURCE_PREFIX, help="awalan IRI resource yang diperiksa")
    ap.add_argument("--show", action="store_true", help="cetak semua triple RDFa (Turtle)")
    args = ap.parse_args()

    if Path(args.source).exists():
        if not args.base:
            sys.exit("Untuk berkas, isi --base dengan alamat halaman aslinya.")
        html, base = Path(args.source).read_text(encoding="utf-8"), args.base
    else:
        r = requests.get(args.source, timeout=60, headers={"User-Agent": "CinelogStudentProject/0.1"})
        r.raise_for_status()
        html, base = r.content.decode("utf-8"), args.source
    # Diberikan sebagai aliran byte (bukan nama berkas): pyRdfa membuka berkas dengan encoding bawaan Windows (cp1252)
    # sehingga huruf beraksen rusak. Dengan byte UTF-8, pembacaan mengikuti <meta charset="utf-8"> di halaman.
    graph = pyRdfa(Options(), base=base, media_type="text/html").graph_from_source(io.BytesIO(html.encode("utf-8")))

    if args.show:
        print(graph.serialize(format="turtle"))
    checked = missing = skipped = 0
    for s, p, o in graph:
        if isinstance(s, BNode) or isinstance(o, BNode) or not str(s).startswith(args.resource_prefix):
            skipped += 1
            continue
        checked += 1
        ask = f"ASK {{ {s.n3()} {p.n3()} {o.n3()} }}"
        resp = requests.post(args.fuseki + "/sparql", data={"query": ask}, timeout=30,
                             headers={"Accept": "application/sparql-results+json"})
        resp.raise_for_status()
        if not resp.json()["boolean"]:
            missing += 1
            print(f"TIDAK ADA di Fuseki: {s.n3()} {p.n3()} {o.n3()}")

    print(f"\nTriple RDFa total      : {len(graph)}")
    print(f"Diperiksa ke Fuseki    : {checked}")
    print(f"Dilewati (blank node / bukan resource kita): {skipped}")
    print(f"Tidak ada di Fuseki    : {missing}")
    print("HASIL: " + ("VALID, semua triple RDFa ada di dataset." if missing == 0 and checked else "ADA MASALAH, lihat daftar di atas."))
    sys.exit(0 if missing == 0 and checked else 1)


if __name__ == "__main__":
    main()