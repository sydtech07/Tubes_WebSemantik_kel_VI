"""Langkah 8 - Muat data/clean/external_reviews.csv ke tabel MariaDB `external_reviews`.

Prasyarat: tabel sudah dibuat (database/sql/patch_external_reviews.sql) dan 07_fetch_reviews.py sudah dijalankan.
Idempoten: dijalankan berulang tidak menggandakan data (kunci unik: source + source_review_id).

Contoh:
  python pipeline/08_import_reviews.py
"""
from __future__ import annotations

import csv
import os
import sys

import pymysql

from common import CLEAN_DIR, setup_logging

log = setup_logging("import_reviews")
CSV_PATH = CLEAN_DIR / "external_reviews.csv"

UPSERT = """
INSERT INTO external_reviews
  (movie_id, source, source_review_id, author, author_username, rating, content, language, source_url, posted_at)
VALUES (%s, 'tmdb', %s, %s, %s, %s, %s, %s, %s, %s)
ON DUPLICATE KEY UPDATE
  author = VALUES(author), author_username = VALUES(author_username), rating = VALUES(rating),
  content = VALUES(content), language = VALUES(language), source_url = VALUES(source_url),
  posted_at = VALUES(posted_at)
"""


def connect() -> pymysql.connections.Connection:
    return pymysql.connect(
        host=os.getenv("DB_HOST", "127.0.0.1"),
        port=int(os.getenv("DB_PORT", "3307")),
        user=os.getenv("DB_USERNAME", "cinelog"),
        password=os.getenv("DB_PASSWORD", ""),
        database=os.getenv("DB_DATABASE", "cinelog"),
        charset="utf8mb4",
        autocommit=False,
    )


def blank_to_none(value: str):
    return None if value is None or value == "" else value


def main() -> None:
    if not CSV_PATH.exists():
        sys.exit(f"{CSV_PATH} belum ada. Jalankan dulu: python pipeline/07_fetch_reviews.py")

    with open(CSV_PATH, encoding="utf-8", newline="") as fh:
        rows = list(csv.DictReader(fh))
    log.info("Membaca %d ulasan dari CSV", len(rows))

    conn = connect()
    try:
        with conn.cursor() as cur:
            try:
                cur.execute("SELECT tmdb_id, id FROM movies")
            except pymysql.err.ProgrammingError as exc:
                sys.exit(f"Tabel movies tidak terbaca: {exc}")
            movie_id = dict(cur.fetchall())

            params, unknown = [], 0
            for r in rows:
                mid = movie_id.get(int(r["tmdb_id"]))
                if mid is None:
                    unknown += 1
                    continue
                params.append((mid, r["source_review_id"], r["author"], blank_to_none(r["author_username"]),
                               blank_to_none(r["rating"]), r["content"], blank_to_none(r["language"]),
                               blank_to_none(r["source_url"]), blank_to_none(r["posted_at"])))
            if unknown:
                log.warning("%d ulasan dilewati: film tidak ada di tabel movies", unknown)

            try:
                for start in range(0, len(params), 500):
                    cur.executemany(UPSERT, params[start:start + 500])
            except pymysql.err.ProgrammingError as exc:
                conn.rollback()
                sys.exit("Tabel external_reviews belum ada? Jalankan database/sql/patch_external_reviews.sql dulu. "
                         f"Detail: {exc}")
            conn.commit()

            cur.execute("SELECT COUNT(*), COUNT(DISTINCT movie_id) FROM external_reviews WHERE source = 'tmdb'")
            total, films = cur.fetchone()
    finally:
        conn.close()

    log.info("Di database sekarang: %d ulasan TMDB untuk %d film", total, films)
    if total == len(params):
        log.info("Verifikasi berhasil: jumlah ulasan di database cocok dengan CSV.")
    else:
        log.warning("Jumlah di database (%d) berbeda dari CSV yang diproses (%d). Mungkin ada ulasan dari "
                    "unduhan sebelumnya yang tidak ada di CSV ini.", total, len(params))


if __name__ == "__main__":
    main()
