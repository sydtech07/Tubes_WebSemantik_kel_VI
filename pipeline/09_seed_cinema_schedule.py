"""Buat dataset auditorium + jadwal tayang buatan sendiri untuk demonstrasi Web Semantik.

Dataset ini sengaja bersifat sintetis untuk memenuhi kebutuhan dataset buatan sendiri pada tugas.
Nama bioskop, koordinat, dan film diambil dari dataset proyek yang sudah ada; auditorium,
jadwal, harga, dan format tayang dibuat oleh proyek dan BUKAN jadwal resmi bioskop.

Contoh:
  python pipeline/09_seed_cinema_schedule.py
  python pipeline/09_seed_cinema_schedule.py --start 2026-11-17 --days 7
"""
from __future__ import annotations

import argparse
import csv
from datetime import date, datetime, timedelta
from pathlib import Path

from rdfcommon import CLEAN_DIR, read_csv, read_cinemas

AUDITORIUMS = CLEAN_DIR / "auditoriums.csv"
SCREENINGS = CLEAN_DIR / "screenings.csv"

# Delapan film terpopuler dari data/clean/movies.csv. Urutan dipakai untuk
# membagi film ke dua auditorium dan empat slot per hari.
# Dengan demikian jadwal sintetis tetap kompatibel saat dataset TMDB diperbarui.
SCHEDULE_MOVIE_COUNT = 8
SLOTS = ["10:00", "13:00", "16:00", "19:00"]


def load_movies() -> dict[str, dict[str, str]]:
    return {str(row["tmdb_id"]): row for row in read_csv("movies.csv")}


def make_auditoriums(cinemas: list[dict]) -> list[dict[str, str]]:
    rows = []
    for cinema in cinemas:
        for screen in (1, 2):
            rows.append({
                "auditorium_id": f"{cinema['id']}-studio-{screen}",
                "cinema_id": cinema["id"],
                "name": f"Studio {screen}",
                "screen_number": str(screen),
                # Kapasitas adalah nilai sintetis untuk dataset tugas.
                "capacity": "120" if screen == 1 else "150",
                "format": "Regular 2D",
            })
    return rows


def make_screenings(cinemas: list[dict], start: date, days: int, movies: dict[str, dict[str, str]]) -> list[dict[str, str]]:
    selected = sorted(
        movies.values(),
        key=lambda m: float(m.get("tmdb_popularity") or 0),
        reverse=True,
    )[:SCHEDULE_MOVIE_COUNT]
    if len(selected) < SCHEDULE_MOVIE_COUNT:
        raise SystemExit(f"movies.csv hanya memiliki {len(selected)} film; minimal {SCHEDULE_MOVIE_COUNT} diperlukan")
    selected_ids = [str(m["tmdb_id"]) for m in selected]

    rows = []
    # Studio 1 menjalankan indeks film genap; Studio 2 indeks film ganjil.
    # Durasi film dipakai untuk menghitung end_datetime secara konsisten.
    for day_offset in range(days):
        day = start + timedelta(days=day_offset)
        weekend = day.weekday() >= 5
        price = "55000" if weekend else "45000"
        for cinema in cinemas:
            for studio in (1, 2):
                auditorium_id = f"{cinema['id']}-studio-{studio}"
                movie_indexes = [0, 2, 4, 6] if studio == 1 else [1, 3, 5, 7]
                for slot_index, movie_index in enumerate(movie_indexes):
                    movie_id = selected_ids[movie_index]
                    runtime = int(float(movies[movie_id]["runtime"] or 0))
                    hour, minute = map(int, SLOTS[slot_index].split(":"))
                    start_dt = datetime(day.year, day.month, day.day, hour, minute)
                    # Buffer 15 menit untuk pergantian penonton/studio.
                    end_dt = start_dt + timedelta(minutes=runtime + 15)
                    screening_id = f"{day:%Y%m%d}-{cinema['id']}-s{studio}-{slot_index + 1}"
                    rows.append({
                        "screening_id": screening_id,
                        "movie_tmdb_id": movie_id,
                        "cinema_id": cinema["id"],
                        "auditorium_id": auditorium_id,
                        "start_datetime": start_dt.strftime("%Y-%m-%dT%H:%M:%S+07:00"),
                        "end_datetime": end_dt.strftime("%Y-%m-%dT%H:%M:%S+07:00"),
                        "video_format": "2D",
                        "subtitle_language": "id",
                        "price": price,
                        "currency": "IDR",
                        "status": "scheduled",
                        "ticket_url": "",
                        "source_url": "",
                    })
    return rows


def write_csv(path: Path, fieldnames: list[str], rows: list[dict[str, str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--start", default="2026-11-17", help="tanggal awal YYYY-MM-DD")
    parser.add_argument("--days", type=int, default=7, help="jumlah hari jadwal")
    args = parser.parse_args()
    if args.days < 1 or args.days > 31:
        raise SystemExit("--days harus 1..31")
    try:
        start = date.fromisoformat(args.start)
    except ValueError as exc:
        raise SystemExit("--start harus berformat YYYY-MM-DD") from exc

    cinemas = read_cinemas()
    movies = load_movies()
    auditoriums = make_auditoriums(cinemas)
    screenings = make_screenings(cinemas, start, args.days, movies)

    write_csv(AUDITORIUMS,
              ["auditorium_id", "cinema_id", "name", "screen_number", "capacity", "format"],
              auditoriums)
    write_csv(SCREENINGS,
              ["screening_id", "movie_tmdb_id", "cinema_id", "auditorium_id", "start_datetime",
               "end_datetime", "video_format", "subtitle_language", "price", "currency", "status",
               "ticket_url", "source_url"],
              screenings)

    print(f"Bioskop       : {len(cinemas)}")
    print(f"Auditorium    : {len(auditoriums)}")
    print(f"Film terjadwal: {SCHEDULE_MOVIE_COUNT}")
    print(f"Jadwal tayang : {len(screenings)}")
    print(f"Periode       : {start} s/d {start + timedelta(days=args.days - 1)}")
    print(f"Output        : {AUDITORIUMS}")
    print(f"                {SCREENINGS}")


if __name__ == "__main__":
    main()
