"""Bukti RDFa + Open Graph halaman film CINÉLOG (bahan paper dan demo).

Yang dilakukan:
  1. Mengunduh halaman /film/{slug} dari aplikasi Laravel.
  2. Mengurai RDFa-nya dengan rdflib (parser RDFa 1.1), seperti yang dilakukan mesin pencari/validator.
  3. Untuk tiap triple yang subjeknya resource CINÉLOG, bertanya ke Fuseki (ASK) apakah triple yang sama
     memang ada di triple store. Triple yang tidak ada ditandai (mis. aggregateRating, yang berasal dari MariaDB).
  4. Menampilkan triple Open Graph (subjek = URL halaman).

Pakai (venv pipeline aktif, aplikasi dan Fuseki menyala):
  python scripts/check_rdfa.py spider-man-brand-new-day-2026-969681
  python scripts/check_rdfa.py <slug> --md docs/hasil_uji_rdfa.md
"""
from __future__ import annotations

import argparse
import json
import sys
import urllib.parse
import urllib.request
from pathlib import Path

from rdflib import BNode, Graph, URIRef

OGP = "https://ogp.me/ns#"
OGP_VIDEO = "https://ogp.me/ns/video#"


def fetch(url: str, data: bytes | None = None, headers: dict | None = None) -> str:
    req = urllib.request.Request(url, data=data, headers=headers or {})
    with urllib.request.urlopen(req, timeout=30) as resp:
        return resp.read().decode("utf-8")


def ask(endpoint: str, s, p, o) -> bool:
    query = f"ASK {{ {s.n3()} {p.n3()} {o.n3()} }}"
    body = urllib.parse.urlencode({"query": query}).encode()
    answer = fetch(endpoint, body, {"Accept": "application/sparql-results+json",
                                    "Content-Type": "application/x-www-form-urlencoded"})
    return bool(json.loads(answer).get("boolean", False))


def short(term, base: str) -> str:
    text = term.n3() if hasattr(term, "n3") else str(term)
    text = text.replace(base, "cin:")
    text = text.replace("https://schema.org/", "schema:")
    text = text.replace("http://www.w3.org/2001/XMLSchema#", "xsd:")
    return text if len(text) <= 90 else text[:87] + "..."


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("slug", help="slug film, mis. spider-man-brand-new-day-2026-969681")
    ap.add_argument("--app", default="http://localhost:8000", help="alamat aplikasi Laravel dari komputermu")
    ap.add_argument("--fuseki", default="http://localhost:3030/cinelog/sparql", help="endpoint SPARQL Fuseki dari komputermu")
    ap.add_argument("--base", default="http://localhost:8000/", help="BASE_URI RDF (sama dengan CINELOG_BASE_URI)")
    ap.add_argument("--md", help="tulis hasil sebagai tabel Markdown ke berkas ini")
    args = ap.parse_args()

    page_url = f"{args.app.rstrip('/')}/film/{args.slug}"
    html = fetch(page_url)

    g = Graph()
    g.parse(data=html, format="rdfa", publicID=f"{args.base}film/{args.slug}")
    resource_prefix = f"{args.base}resource/"

    rows, ogp = [], []
    for s, p, o in sorted(g, key=lambda t: (str(t[0]), str(t[1]), str(t[2]))):
        if str(p).startswith((OGP, OGP_VIDEO)):
            ogp.append((p, o))
            continue
        if isinstance(s, BNode) or isinstance(o, BNode):
            rows.append((s, p, o, "bnode"))
        elif str(s).startswith(resource_prefix):
            rows.append((s, p, o, "ada" if ask(args.fuseki, s, p, o) else "tidak ada"))

    ada = sum(1 for r in rows if r[3] == "ada")
    tidak = [r for r in rows if r[3] == "tidak ada"]
    bnode = [r for r in rows if r[3] == "bnode"]

    print(f"Halaman        : {page_url}")
    print(f"Triple RDFa    : {len(rows)} (resource CINÉLOG) + {len(ogp)} (Open Graph)")
    print(f"Cocok di Fuseki: {ada}")
    print(f"Tidak di Fuseki: {len(tidak)}")
    print(f"Blank node     : {len(bnode)} (mis. aggregateRating dari MariaDB, memang tidak ada di Fuseki)")
    for s, p, o, _ in tidak:
        print(f"  ✗ {short(s, args.base)}  {short(p, args.base)}  {short(o, args.base)}")
    print("\nOpen Graph:")
    for p, o in ogp:
        print(f"  {str(p).replace(OGP_VIDEO, 'video:').replace(OGP, 'og:'):<22} {str(o)[:80]}")

    if args.md:
        lines = [f"# Hasil uji RDFa dan Open Graph: `{args.slug}`", "",
                 f"Halaman: {page_url}", "",
                 f"- Triple RDFa pada resource CINÉLOG: {len(rows)}; cocok di Fuseki: {ada}; tidak ada di Fuseki: {len(tidak)}; blank node: {len(bnode)}",
                 f"- Triple Open Graph: {len(ogp)}", "",
                 "## Tabel. Triple RDFa dibandingkan dengan Fuseki", "",
                 "| No | Subjek | Predikat | Objek | Di Fuseki |", "|---|---|---|---|---|"]
        for i, (s, p, o, st) in enumerate(rows, 1):
            lines.append(f"| {i} | `{short(s, args.base)}` | `{short(p, args.base)}` | `{short(o, args.base)}` | {st} |")
        lines += ["", "## Tabel. Open Graph", "", "| Properti | Nilai |", "|---|---|"]
        for p, o in ogp:
            lines.append(f"| {str(p).replace(OGP_VIDEO, 'video:').replace(OGP, 'og:')} | {str(o)[:100]} |")
        Path(args.md).write_text("\n".join(lines) + "\n", encoding="utf-8")
        print(f"\nTabel ditulis ke {args.md}")

    return 0 if not tidak else 1


if __name__ == "__main__":
    sys.exit(main())
