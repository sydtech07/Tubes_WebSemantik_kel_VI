"""Langkah 1 - Ambil data film dari API resmi TMDB (bukan scraping HTML).

Alur:
  A. /discover/movie  -> daftar kandidat film rilis 2026-01-01 s.d. 2026-09-30, urut popularitas
  B. /movie/{id}?append_to_response=credits,external_ids  -> detail + pemeran/kru + ID eksternal (IMDb, Wikidata)
  C. /movie/{id}?language=id-ID -> sinopsis bahasa Indonesia (jika tersedia)

Hasil mentah disimpan per film di data/raw/movie_<id>.json (cache).
Menjalankan ulang TIDAK mengunduh ulang film yang sudah ada, kecuali memakai --refresh.

Contoh:
  python pipeline/01_fetch_tmdb.py --sample 20      # uji cepat 20 film
  python pipeline/01_fetch_tmdb.py                   # target penuh (default 500 film bersih)
"""
from __future__ import annotations

import argparse
import json
import os
from datetime import datetime, timezone

from common import RAW_DIR, TmdbClient, setup_logging

log = setup_logging("fetch")


def discover_candidates(client: TmdbClient, start: str, end: str, want: int, min_votes: int) -> list[dict]:
    """Kumpulkan sampai `want` kandidat film. Batas TMDB: maksimal 500 halaman x 20 hasil."""
    found: dict[int, dict] = {}
    page = 1
    while len(found) < want:
        data = client.get(
            "discover/movie",
            **{
                "primary_release_date.gte": start,
                "primary_release_date.lte": end,
                "sort_by": "popularity.desc",
                "include_adult": "false",
                "include_video": "false",
                "vote_count.gte": min_votes,
                "language": "en-US",
                "page": page,
            },
        )
        if not data or not data.get("results"):
            break
        for item in data["results"]:
            found.setdefault(item["id"], {
                "id": item["id"],
                "title": item.get("title"),
                "release_date": item.get("release_date"),
                "popularity": item.get("popularity"),
            })
        total_pages = min(data.get("total_pages", 1), 500)
        log.info("discover halaman %d/%d -> %d kandidat", page, total_pages, len(found))
        if page >= total_pages:
            break
        page += 1
    return list(found.values())[:want]


def save_json(path, payload) -> None:
    """Tulis atomik: file sementara dulu, baru diganti, supaya tidak ada file setengah jadi."""
    tmp = path.with_suffix(path.suffix + ".tmp")
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False, indent=1)
    os.replace(tmp, path)


def fetch_details(client: TmdbClient, movie_id: int, with_id: bool) -> dict | None:
    detail = client.get(f"movie/{movie_id}", append_to_response="credits,external_ids", language="en-US")
    if detail is None:
        return None
    if with_id:
        tr = client.get(f"movie/{movie_id}", language="id-ID") or {}
        detail["_id"] = {"title": tr.get("title"), "overview": tr.get("overview"), "tagline": tr.get("tagline")}
    detail["_fetched_at"] = datetime.now(timezone.utc).isoformat(timespec="seconds")
    return detail


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--start", default="2026-01-01")
    ap.add_argument("--end", default="2026-09-30")
    ap.add_argument("--target", type=int, default=500, help="jumlah film bersih yang diinginkan akhirnya")
    ap.add_argument("--buffer", type=float, default=1.4,
                    help="pengali kandidat; sebagian film akan gugur saat pembersihan (default 1.4)")
    ap.add_argument("--min-votes", type=int, default=10, help="vote_count minimal agar film bukan sampah (default 10)")
    ap.add_argument("--sample", type=int, default=0, help="hanya ambil N film pertama (untuk uji cepat)")
    ap.add_argument("--refresh", action="store_true", help="unduh ulang walau sudah ada di cache")
    ap.add_argument("--no-id", action="store_true", help="lewati sinopsis bahasa Indonesia")
    args = ap.parse_args()

    client = TmdbClient(log)
    want = args.sample if args.sample else int(args.target * args.buffer)
    log.info("Mencari %d kandidat film %s s.d. %s", want, args.start, args.end)

    candidates = discover_candidates(client, args.start, args.end, want, args.min_votes)
    save_json(RAW_DIR / "_discover.json", candidates)
    log.info("Kandidat terkumpul: %d", len(candidates))

    ok = skipped = failed = 0
    for i, cand in enumerate(candidates, 1):
        path = RAW_DIR / f"movie_{cand['id']}.json"
        if path.exists() and not args.refresh:
            skipped += 1
            continue
        detail = fetch_details(client, cand["id"], with_id=not args.no_id)
        if detail is None:
            failed += 1
            log.warning("Gagal/404: id=%s (%s)", cand["id"], cand.get("title"))
            continue
        save_json(path, detail)
        ok += 1
        if i % 25 == 0:
            log.info("Progres %d/%d (baru=%d, dilewati=%d, gagal=%d)", i, len(candidates), ok, skipped, failed)

    log.info("Selesai. Baru diunduh=%d, sudah ada=%d, gagal=%d. File ada di %s", ok, skipped, failed, RAW_DIR)
    if failed:
        log.info("Jalankan ulang perintah yang sama untuk mencoba lagi film yang gagal.")


if __name__ == "__main__":
    main()
