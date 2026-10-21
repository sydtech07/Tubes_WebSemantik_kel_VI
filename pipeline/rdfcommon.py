"""Bagian bersama skrip RDF: alamat dasar, namespace, pembaca CSV, pemuat ontologi."""
from __future__ import annotations

import csv
import os
import re

from rdflib import Graph, Namespace

from common import CLEAN_DIR, ROOT

DEFAULT_BASE = "http://localhost:8000/"          # alamat dasar yang tertulis di ontology/cinelog.ttl
BASE_URI = os.getenv("BASE_URI", DEFAULT_BASE)
if not BASE_URI.endswith("/"):
    BASE_URI += "/"

FUSEKI_URL = os.getenv("FUSEKI_URL", "http://localhost:3030/cinelog").rstrip("/")

SCHEMA = Namespace("https://schema.org/")
WD_ENTITY = "http://www.wikidata.org/entity/"
TMDB_IMG = "https://image.tmdb.org/t/p/"

RDF_DIR = ROOT / "data" / "rdf"
RDF_DIR.mkdir(parents=True, exist_ok=True)
ONTOLOGY_FILE = ROOT / "ontology" / "cinelog.ttl"
DATA_TTL = RDF_DIR / "cinelog-data.ttl"


def cin_ns(base: str = BASE_URI) -> Namespace:
    return Namespace(base + "ontology#")


_CONTROL = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]")


def read_csv(name: str) -> list[dict]:
    """Baca CSV bersih. Karakter kontrol tak terlihat dibuang agar Turtle selalu valid bagi parser ketat."""
    path = CLEAN_DIR / name
    if not path.exists():
        raise SystemExit(f"{path} tidak ada. Jalankan 02_clean.py dulu.")
    with open(path, encoding="utf-8-sig", newline="") as fh:
        return [{k: _CONTROL.sub("", v or "") for k, v in row.items()} for row in csv.DictReader(fh)]


def load_ontology(base: str = BASE_URI) -> Graph:
    """Baca ontology/cinelog.ttl; ganti alamat dasar bawaan bila BASE_URI berbeda."""
    if not ONTOLOGY_FILE.exists():
        raise SystemExit(f"{ONTOLOGY_FILE} tidak ada.")
    text = ONTOLOGY_FILE.read_text(encoding="utf-8").replace(DEFAULT_BASE, base)
    g = Graph()
    g.parse(data=text, format="turtle")
    return g
