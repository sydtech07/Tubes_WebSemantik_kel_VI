"""Langkah 7 - Ambil ULASAN pengguna dari TMDB (endpoint /movie/{id}/reviews).

Kenapa TMDB dan bukan scraping IMDb:
  * IMDb melarang robot/screen scraping (Conditions of Use) dan data resminya tidak memuat teks ulasan.
  * TMDB sudah kita pakai (kunci API, jeda, retry ada di common.py) dan menyediakan ulasan lewat API resmi.

Hasil:
  data/raw/reviews_<tmdb_id>.json    cache mentah per film (bisa dilanjutkan bila terputus)
  data/clean/external_reviews.csv    ulasan yang sudah dirapikan (masuk ke MariaDB lewat 08_import_reviews.py)
  data/clean/reviews_report.json     laporan cakupan (berapa film yang punya ulasan)

Contoh:
  python pipeline/07_fetch_reviews.py --sample 30   # UJI DULU: 30 film yang tersebar merata
  python pipeline/07_fetch_reviews.py               # semua film
  python pipeline/07_fetch_reviews.py --refresh     # unduh ulang walau cache sudah ada
"""
from __future__ import annotations

import argparse
import csv
import json
import os
from collections import Counter

from common import CLEAN_DIR, RAW_DIR, TmdbClient, setup_logging

log = setup_logging("reviews")

OUT_CSV = CLEAN_DIR / "external_reviews.csv"
REPORT = CLEAN_DIR / "reviews_report.json"
FIELDS = ["tmdb_id", "source_review_id", "author", "author_username", "rating",
          "content", "language", "source_url", "posted_at"]


def save_json(path, payload) -> None:
    tmp = path.with_suffix(path.suffix + ".tmp")
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False, indent=1)
    os.replace(tmp, path)


def load_movie_ids() -> list[int]:
    with open(CLEAN_DIR / "movies.csv", encoding="utf-8-sig", newline="") as fh:
        return [int(row["tmdb_id"]) for row in csv.DictReader(fh)]


def fetch_reviews(client: TmdbClient, tmdb_id: int, max_pages: int) -> dict | None:
    """Semua ulasan satu film (maks. max_pages halaman, 20 ulasan per halaman). None bila film tidak ditemukan."""
    first = client.get(f"movie/{tmdb_id}/reviews", page=1)
    if first is None:
        return None
    results = list(first.get("results", []))
    total_pages = int(first.get("total_pages") or 1)
    for page in range(2, min(total_pages, max_pages) + 1):
        nxt = client.get(f"movie/{tmdb_id}/reviews", page=page)
        if nxt is None:
            break
        results.extend(nxt.get("results", []))
    return {"id": tmdb_id, "total_results": first.get("total_results", len(results)), "results": results}


def normalise(tmdb_id: int, review: dict) -> dict | None:
    """Satu ulasan TMDB -> satu baris CSV. None bila tidak layak simpan (tanpa id atau tanpa isi)."""
    details = review.get("author_details") or {}
    content = (review.get("content") or "").replace("\r\n", "\n").replace("\r", "\n").strip()
    if not review.get("id") or not content:
        return None
    rating = details.get("rating")
    return {
        "tmdb_id": tmdb_id,
        "source_review_id": review["id"],
        "author": (review.get("author") or details.get("username") or "Anonim").strip()[:150],
        "author_username": (details.get("username") or "").strip()[:150],
        "rating": "" if rating is None else rating,
        "content": content,
        "language": review.get("iso_639_1") or "",
        "source_url": review.get("url") or "",
        "posted_at": (review.get("created_at") or "")[:19].replace("T", " "),   # UTC, mis. 2026-07-01 08:30:12
    }


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--sample", type=int, default=0, help="hanya N film yang tersebar merata (uji cakupan)")
    ap.add_argument("--max-pages", type=int, default=3, help="maks. halaman ulasan per film (20 ulasan/halaman)")
    ap.add_argument("--refresh", action="store_true", help="abaikan cache, unduh ulang")
    args = ap.parse_args()

    ids = load_movie_ids()
    if args.sample:
        step = max(1, len(ids) // args.sample)
        ids = ids[::step][: args.sample]
    log.info("Memproses %d film", len(ids))

    client = TmdbClient(log)
    fetched = cached = failed = 0
    for i, tmdb_id in enumerate(ids, 1):
        path = RAW_DIR / f"reviews_{tmdb_id}.json"
        if path.exists() and not args.refresh:
            cached += 1
            continue
        data = fetch_reviews(client, tmdb_id, args.max_pages)
        if data is None:
            failed += 1
            log.warning("Gagal/404: tmdb_id=%s", tmdb_id)
            continue
        save_json(path, data)
        fetched += 1
        if i % 50 == 0:
            log.info("Progres %d/%d (baru=%d, cache=%d, gagal=%d)", i, len(ids), fetched, cached, failed)
    log.info("Unduh selesai. Baru=%d, dari cache=%d, gagal=%d", fetched, cached, failed)

    # ---- rapikan ke CSV + hitung cakupan ----
    rows, per_film = [], Counter()
    for tmdb_id in ids:
        path = RAW_DIR / f"reviews_{tmdb_id}.json"
        if not path.exists():
            continue
        with open(path, encoding="utf-8") as fh:
            data = json.load(fh)
        per_film[tmdb_id] = 0
        for review in data.get("results", []):
            row = normalise(tmdb_id, review)
            if row:
                rows.append(row)
                per_film[tmdb_id] += 1

    with open(OUT_CSV, "w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=FIELDS)
        writer.writeheader()
        writer.writerows(rows)

    counts = list(per_film.values())
    with_reviews = sum(1 for c in counts if c > 0)
    buckets = Counter("0" if c == 0 else "1" if c == 1 else "2-3" if c <= 3 else "4-9" if c <= 9 else "10+" for c in counts)
    report = {
        "films_dicek": len(counts),
        "films_punya_ulasan": with_reviews,
        "cakupan_persen": round(100 * with_reviews / len(counts), 1) if counts else 0,
        "total_ulasan": len(rows),
        "ulasan_dengan_rating": sum(1 for r in rows if r["rating"] != ""),
        "rata2_ulasan_per_film_yang_punya": round(len(rows) / with_reviews, 2) if with_reviews else 0,
        "sebaran_jumlah_ulasan_per_film": {k: buckets.get(k, 0) for k in ("0", "1", "2-3", "4-9", "10+")},
        "film_terbanyak_ulasan": [{"tmdb_id": t, "ulasan": c} for t, c in per_film.most_common(5) if c > 0],
    }
    save_json(REPORT, report)

    log.info("=== Cakupan ulasan ===")
    log.info("Film dicek: %d | punya ulasan: %d (%.1f%%)", report["films_dicek"], with_reviews, report["cakupan_persen"])
    log.info("Total ulasan: %d (dengan rating: %d)", report["total_ulasan"], report["ulasan_dengan_rating"])
    log.info("Sebaran ulasan per film: %s", report["sebaran_jumlah_ulasan_per_film"])
    log.info("CSV: %s", OUT_CSV)


if __name__ == "__main__":
    main()
