"""Langkah 1b - Tambahkan KEYWORD (tema) TMDB ke file mentah yang sudah ada.

Tidak mengunduh ulang data lain. Hanya menambah kunci "keywords" ke tiap data/raw/movie_<id>.json.
Aman dijalankan berulang: film yang sudah punya "keywords" dilewati.

Contoh:
  python pipeline/01b_fetch_keywords.py --sample 5   # uji cepat 5 film
  python pipeline/01b_fetch_keywords.py              # semua film
"""
from __future__ import annotations

import argparse
import json
import os

from common import RAW_DIR, TmdbClient, setup_logging

log = setup_logging("keywords")


def save_json(path, payload) -> None:
    tmp = path.with_suffix(path.suffix + ".tmp")
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False, indent=1)
    os.replace(tmp, path)


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--sample", type=int, default=0, help="hanya proses N film pertama (uji cepat)")
    args = ap.parse_args()

    client = TmdbClient(log)
    files = sorted(RAW_DIR.glob("movie_*.json"))
    if args.sample:
        files = files[: args.sample]
    log.info("Memproses %d file mentah", len(files))

    ok = skipped = failed = 0
    for i, path in enumerate(files, 1):
        with open(path, encoding="utf-8") as fh:
            detail = json.load(fh)
        if "keywords" in detail:
            skipped += 1
            continue
        movie_id = detail.get("id") or int(path.stem.split("_")[1])
        resp = client.get(f"movie/{movie_id}/keywords")
        if resp is None:
            failed += 1
            log.warning("Gagal/404: id=%s", movie_id)
            continue
        detail["keywords"] = {"keywords": resp.get("keywords", [])}
        save_json(path, detail)
        ok += 1
        if i % 50 == 0:
            log.info("Progres %d/%d (baru=%d, dilewati=%d, gagal=%d)", i, len(files), ok, skipped, failed)

    log.info("Selesai. Baru=%d, sudah ada=%d, gagal=%d", ok, skipped, failed)
    if failed:
        log.info("Jalankan ulang perintah yang sama untuk mencoba lagi yang gagal.")


if __name__ == "__main__":
    main()