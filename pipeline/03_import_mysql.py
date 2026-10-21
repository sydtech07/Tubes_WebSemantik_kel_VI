"""Langkah 3 - Impor CSV bersih ke MariaDB/MySQL (idempoten).

Aman dijalankan berulang kali:
  - genres/movies/people memakai INSERT ... ON DUPLICATE KEY UPDATE pada kolom tmdb_id (unik)
    sehingga id internal TIDAK berubah dan review yang sudah ada tidak yatim.
  - Kolom reviews_count & avg_rating TIDAK disentuh (dikelola trigger).
  - movie_genres & movie_credits milik film yang diimpor diganti seluruhnya, dalam SATU transaksi.
    Jika ada error, semuanya dibatalkan (rollback).

Contoh:
  python pipeline/03_import_mysql.py --dry-run   # uji tanpa menyimpan
  python pipeline/03_import_mysql.py
"""
from __future__ import annotations

import argparse
import csv
import os

import pymysql

from common import CLEAN_DIR, setup_logging

log = setup_logging("import")


def read_csv(name: str) -> list[dict]:
    path = CLEAN_DIR / name
    if not path.exists():
        raise SystemExit(f"{path} tidak ada. Jalankan 02_clean.py dulu.")
    with open(path, encoding="utf-8-sig", newline="") as fh:
        return list(csv.DictReader(fh))


def nul(value):
    """String kosong di CSV -> NULL di database."""
    return None if value is None or value == "" else value


def to_int(value):
    value = nul(value)
    return None if value is None else int(float(value))


def to_float(value):
    value = nul(value)
    return None if value is None else float(value)


def chunks(items: list, size: int):
    for i in range(0, len(items), size):
        yield items[i:i + size]


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


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--dry-run", action="store_true", help="jalankan semua langkah lalu rollback")
    args = ap.parse_args()

    genres = read_csv("genres.csv")
    movies = read_csv("movies.csv")
    people = read_csv("people.csv")
    movie_genres = read_csv("movie_genres.csv")
    credits = read_csv("movie_credits.csv")
    log.info("CSV dibaca: %d film, %d genre, %d orang, %d relasi genre, %d credit",
             len(movies), len(genres), len(people), len(movie_genres), len(credits))

    conn = connect()
    try:
        with conn.cursor() as cur:
            cur.executemany(
                "INSERT INTO genres (tmdb_id, name) VALUES (%s, %s) "
                "ON DUPLICATE KEY UPDATE name = VALUES(name)",
                [(int(g["tmdb_id"]), g["name"]) for g in genres],
            )

            cur.executemany(
                """INSERT INTO movies
                   (tmdb_id, imdb_id, wikidata_id, title, original_title, slug, tagline, overview_en,
                    overview_id, release_date, runtime, original_language, poster_path, backdrop_path,
                    homepage, budget, revenue, tmdb_popularity, tmdb_vote_average, tmdb_vote_count, status)
                   VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                   ON DUPLICATE KEY UPDATE
                    imdb_id=VALUES(imdb_id), wikidata_id=VALUES(wikidata_id), title=VALUES(title),
                    original_title=VALUES(original_title), slug=VALUES(slug), tagline=VALUES(tagline),
                    overview_en=VALUES(overview_en), overview_id=VALUES(overview_id),
                    release_date=VALUES(release_date), runtime=VALUES(runtime),
                    original_language=VALUES(original_language), poster_path=VALUES(poster_path),
                    backdrop_path=VALUES(backdrop_path), homepage=VALUES(homepage), budget=VALUES(budget),
                    revenue=VALUES(revenue), tmdb_popularity=VALUES(tmdb_popularity),
                    tmdb_vote_average=VALUES(tmdb_vote_average), tmdb_vote_count=VALUES(tmdb_vote_count),
                    status=VALUES(status)""",
                [(int(m["tmdb_id"]), nul(m["imdb_id"]), nul(m["wikidata_id"]), m["title"],
                  nul(m["original_title"]), m["slug"], nul(m["tagline"]), nul(m["overview_en"]),
                  nul(m["overview_id"]), m["release_date"], to_int(m["runtime"]),
                  nul(m["original_language"]), nul(m["poster_path"]), nul(m["backdrop_path"]),
                  nul(m["homepage"]), to_int(m["budget"]), to_int(m["revenue"]),
                  to_float(m["tmdb_popularity"]), to_float(m["tmdb_vote_average"]),
                  to_int(m["tmdb_vote_count"]), nul(m["status"])) for m in movies],
            )

            for batch in chunks(people, 500):
                cur.executemany(
                    """INSERT INTO people (tmdb_id, name, gender, known_for_department, profile_path)
                       VALUES (%s,%s,%s,%s,%s)
                       ON DUPLICATE KEY UPDATE name=VALUES(name), gender=VALUES(gender),
                         known_for_department=VALUES(known_for_department), profile_path=VALUES(profile_path)""",
                    [(int(p["tmdb_id"]), p["name"], to_int(p["gender"]), nul(p["known_for_department"]),
                      nul(p["profile_path"])) for p in batch],
                )

            # Peta tmdb_id -> id internal
            def id_map(table: str) -> dict[int, int]:
                cur.execute(f"SELECT tmdb_id, id FROM {table}")
                return {t: i for t, i in cur.fetchall()}

            movie_ids, genre_ids, person_ids = id_map("movies"), id_map("genres"), id_map("people")
            imported_movie_ids = [movie_ids[int(m["tmdb_id"])] for m in movies]

            # Ganti relasi film yang diimpor (hapus lalu isi ulang) agar data basi tidak tersisa
            for batch in chunks(imported_movie_ids, 200):
                marks = ",".join(["%s"] * len(batch))
                cur.execute(f"DELETE FROM movie_genres WHERE movie_id IN ({marks})", batch)
                cur.execute(f"DELETE FROM movie_credits WHERE movie_id IN ({marks})", batch)

            cur.executemany(
                "INSERT INTO movie_genres (movie_id, genre_id) VALUES (%s, %s)",
                [(movie_ids[int(r["movie_tmdb_id"])], genre_ids[int(r["genre_tmdb_id"])]) for r in movie_genres],
            )
            for batch in chunks(credits, 1000):
                cur.executemany(
                    """INSERT INTO movie_credits (movie_id, person_id, credit_type, role, department, billing_order)
                       VALUES (%s,%s,%s,%s,%s,%s)""",
                    [(movie_ids[int(r["movie_tmdb_id"])], person_ids[int(r["person_tmdb_id"])],
                      r["credit_type"], r["role"], nul(r["department"]), to_int(r["billing_order"]))
                     for r in batch],
                )

        if args.dry_run:
            conn.rollback()
            log.info("DRY-RUN: semua langkah berhasil, perubahan dibatalkan (rollback).")
        else:
            conn.commit()
            with conn.cursor() as cur:
                for table in ("movies", "genres", "people", "movie_genres", "movie_credits"):
                    cur.execute(f"SELECT COUNT(*) FROM {table}")
                    log.info("Total baris %-14s = %d", table, cur.fetchone()[0])
            log.info("Impor selesai dan tersimpan (commit).")
    except Exception:
        conn.rollback()
        log.exception("Impor gagal, semua perubahan dibatalkan (rollback).")
        raise
    finally:
        conn.close()


if __name__ == "__main__":
    main()
