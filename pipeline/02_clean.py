"""Langkah 2 - Bersihkan & validasi data mentah, hasilkan CSV siap impor.

Masukan : data/raw/movie_*.json
Keluaran: data/clean/movies.csv, genres.csv, movie_genres.csv, people.csv, movie_credits.csv, report.json

Aturan kelayakan sebuah film (yang tidak lolos dicatat di report.json beserta alasannya):
  - tanggal rilis ada dan berada di rentang --start .. --end
  - status "Released", bukan film dewasa
  - punya poster, sinopsis (Inggris atau Indonesia), runtime > 0, minimal 1 genre, minimal 1 pemeran
Setelah lolos, diambil --max film teratas menurut popularitas.
CSV memakai UTF-8 dengan BOM agar langsung terbaca benar di Excel.
"""
from __future__ import annotations

import argparse
import csv
import json
import re
import unicodedata
from collections import Counter

from common import CLEAN_DIR, RAW_DIR, setup_logging

log = setup_logging("clean")

MAX_CAST = 15
KEEP_CREW_JOBS = {"Director", "Screenplay", "Writer", "Original Music Composer"}

MOVIE_COLS = ["tmdb_id", "imdb_id", "wikidata_id", "title", "original_title", "slug", "tagline",
              "overview_en", "overview_id", "release_date", "runtime", "original_language",
              "poster_path", "backdrop_path", "homepage", "budget", "revenue",
              "tmdb_popularity", "tmdb_vote_average", "tmdb_vote_count", "status"]


def slugify(text: str) -> str:
    text = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode("ascii")
    text = re.sub(r"[^a-zA-Z0-9]+", "-", text).strip("-").lower()
    return text or "film"


def clean_text(value) -> str:
    return re.sub(r"\s+", " ", value).strip() if isinstance(value, str) else ""


def zero_to_blank(value):
    """TMDB memakai 0 untuk 'tidak diketahui' pada budget/revenue."""
    return value if value else ""


def evaluate(raw: dict, start: str, end: str) -> tuple[str | None, dict | None]:
    """Kembalikan (alasan_gugur, None) atau (None, baris_film)."""
    release = clean_text(raw.get("release_date"))
    if not release:
        return "tanpa_tanggal_rilis", None
    if not (start <= release <= end):
        return "di_luar_rentang_tanggal", None
    if raw.get("adult"):
        return "film_dewasa", None
    if raw.get("status") != "Released":
        return "belum_rilis", None
    if not raw.get("poster_path"):
        return "tanpa_poster", None

    overview_en = clean_text(raw.get("overview"))
    overview_id = clean_text((raw.get("_id") or {}).get("overview"))
    if not (overview_en or overview_id):
        return "tanpa_sinopsis", None
    if not raw.get("runtime") or raw["runtime"] <= 0:
        return "runtime_tidak_valid", None
    if not raw.get("genres"):
        return "tanpa_genre", None
    if not (raw.get("credits") or {}).get("cast"):
        return "tanpa_pemeran", None

    title = clean_text(raw.get("title"))
    if not title:
        return "tanpa_judul", None

    ext = raw.get("external_ids") or {}
    year = release[:4]
    row = {
        "tmdb_id": raw["id"],
        "imdb_id": clean_text(raw.get("imdb_id") or ext.get("imdb_id")),
        "wikidata_id": clean_text(ext.get("wikidata_id")),
        "title": title,
        "original_title": clean_text(raw.get("original_title")),
        "slug": f"{slugify(title)}-{year}-{raw['id']}",
        "tagline": clean_text(raw.get("tagline")),
        "overview_en": overview_en,
        "overview_id": overview_id,
        "release_date": release,
        "runtime": raw["runtime"],
        "original_language": clean_text(raw.get("original_language")),
        "poster_path": raw.get("poster_path") or "",
        "backdrop_path": raw.get("backdrop_path") or "",
        "homepage": clean_text(raw.get("homepage")),
        "budget": zero_to_blank(raw.get("budget")),
        "revenue": zero_to_blank(raw.get("revenue")),
        "tmdb_popularity": raw.get("popularity") if raw.get("popularity") is not None else "",
        "tmdb_vote_average": raw.get("vote_average") if raw.get("vote_average") is not None else "",
        "tmdb_vote_count": raw.get("vote_count") if raw.get("vote_count") is not None else "",
        "status": raw.get("status") or "",
    }
    return None, row


def write_csv(name: str, columns: list[str], rows: list[dict]) -> None:
    with open(CLEAN_DIR / name, "w", encoding="utf-8-sig", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=columns)
        writer.writeheader()
        writer.writerows(rows)


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--start", default="2026-01-01")
    ap.add_argument("--end", default="2026-09-30")
    ap.add_argument("--max", type=int, default=500, help="jumlah film akhir (default 500)")
    args = ap.parse_args()

    files = sorted(RAW_DIR.glob("movie_*.json"))
    if not files:
        raise SystemExit("Tidak ada file di data/raw. Jalankan 01_fetch_tmdb.py dulu.")

    dropped: Counter = Counter()
    passed: list[tuple[dict, dict]] = []
    seen_ids: set[int] = set()
    for path in files:
        raw = json.loads(path.read_text(encoding="utf-8"))
        if raw.get("id") in seen_ids:
            dropped["duplikat"] += 1
            continue
        reason, row = evaluate(raw, args.start, args.end)
        if reason:
            dropped[reason] += 1
            continue
        seen_ids.add(raw["id"])
        passed.append((raw, row))

    passed.sort(key=lambda pair: float(pair[1]["tmdb_popularity"] or 0), reverse=True)
    trimmed = max(0, len(passed) - args.max)
    passed = passed[: args.max]

    movies, genres, movie_genres = [], {}, []
    people, credits = {}, {}
    for raw, row in passed:
        movies.append(row)
        for g in raw["genres"]:
            genres[g["id"]] = clean_text(g["name"])
            movie_genres.append({"movie_tmdb_id": raw["id"], "genre_tmdb_id": g["id"]})

        for c in (raw.get("credits") or {}).get("cast", []):
            if c.get("order", 999) >= MAX_CAST:
                continue
            people[c["id"]] = c
            credits[(raw["id"], c["id"], "cast", clean_text(c.get("character")))] = {
                "movie_tmdb_id": raw["id"], "person_tmdb_id": c["id"], "credit_type": "cast",
                "role": clean_text(c.get("character")), "department": "Acting",
                "billing_order": c.get("order", ""),
            }
        for c in (raw.get("credits") or {}).get("crew", []):
            if c.get("job") not in KEEP_CREW_JOBS:
                continue
            people[c["id"]] = c
            credits[(raw["id"], c["id"], "crew", c["job"])] = {
                "movie_tmdb_id": raw["id"], "person_tmdb_id": c["id"], "credit_type": "crew",
                "role": c["job"], "department": clean_text(c.get("department")), "billing_order": "",
            }

    people_rows = [{
        "tmdb_id": pid, "name": clean_text(p.get("name")), "gender": p.get("gender", ""),
        "known_for_department": clean_text(p.get("known_for_department")),
        "profile_path": p.get("profile_path") or "",
    } for pid, p in sorted(people.items())]

    write_csv("movies.csv", MOVIE_COLS, movies)
    write_csv("genres.csv", ["tmdb_id", "name"], [{"tmdb_id": k, "name": v} for k, v in sorted(genres.items())])
    write_csv("movie_genres.csv", ["movie_tmdb_id", "genre_tmdb_id"], movie_genres)
    write_csv("people.csv", ["tmdb_id", "name", "gender", "known_for_department", "profile_path"], people_rows)
    write_csv("movie_credits.csv",
              ["movie_tmdb_id", "person_tmdb_id", "credit_type", "role", "department", "billing_order"],
              list(credits.values()))

    per_month = Counter(m["release_date"][:7] for m in movies)
    n = len(movies) or 1
    report = {
        "raw_files": len(files),
        "film_lolos_validasi": len(passed) + trimmed,
        "film_akhir": len(movies),
        "dipangkas_karena_melebihi_max": trimmed,
        "gugur_per_alasan": dict(dropped),
        "film_per_bulan": dict(sorted(per_month.items())),
        "jumlah_genre": len(genres),
        "jumlah_orang": len(people_rows),
        "jumlah_credit": len(credits),
        "persen_punya_imdb_id": round(100 * sum(1 for m in movies if m["imdb_id"]) / n, 1),
        "persen_punya_wikidata_id": round(100 * sum(1 for m in movies if m["wikidata_id"]) / n, 1),
        "persen_punya_sinopsis_id": round(100 * sum(1 for m in movies if m["overview_id"]) / n, 1),
    }
    (CLEAN_DIR / "report.json").write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    log.info("Laporan kualitas data:\n%s", json.dumps(report, ensure_ascii=False, indent=2))
    if len(movies) < 300:
        log.warning("Film akhir (%d) di bawah 300. Naikkan --target/--buffer atau turunkan --min-votes "
                    "di langkah 1, lalu jalankan ulang.", len(movies))


if __name__ == "__main__":
    main()
