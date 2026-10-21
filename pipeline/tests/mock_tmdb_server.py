"""Server TMDB TIRUAN untuk menguji pipeline tanpa API key dan tanpa internet.

Semua data di sini PALSU (nama film, aktor, ID Wikidata). Bentuk JSON-nya meniru dokumentasi TMDB v3
(discover/movie, movie/{id} + append_to_response=credits,external_ids). Server ini sengaja menyisipkan:
  - film cacat (tanpa poster / sinopsis / genre / pemeran, runtime 0) untuk menguji pembersihan
  - film di luar rentang tanggal
  - satu respons HTTP 429 untuk menguji retry

Jalankan:
  python pipeline/tests/mock_tmdb_server.py --port 8099
Lalu di terminal lain (Linux/macOS):
  TMDB_BASE_URL=http://127.0.0.1:8099/3 TMDB_READ_TOKEN=dummy python pipeline/01_fetch_tmdb.py
"""
from __future__ import annotations

import argparse
import json
import random
from datetime import date, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

GENRES = [(28, "Action"), (12, "Adventure"), (16, "Animation"), (35, "Comedy"), (80, "Crime"),
          (18, "Drama"), (14, "Fantasy"), (27, "Horror"), (878, "Science Fiction"), (53, "Thriller")]


def build_movies(n: int) -> dict[int, dict]:
    rnd = random.Random(2026)
    people = [{"id": 5000 + i, "name": f"Aktor Uji {i}", "gender": 1 + i % 2,
               "known_for_department": "Acting", "profile_path": f"/p{i}.jpg"} for i in range(60)]
    movies: dict[int, dict] = {}
    for i in range(n):
        mid = 1000 + i
        if i % 29 == 0:   # di luar rentang (Desember 2025)
            rel = date(2025, 12, 15) + timedelta(days=i % 10)
        else:
            rel = date(2026, 1, 1) + timedelta(days=rnd.randint(0, 272))
        cast = []
        for order, p in enumerate(rnd.sample(people, 20)):
            cast.append({**p, "character": f"Tokoh {order}", "order": order, "credit_id": f"c{mid}-{order}"})
        crew = [
            {**people[i % 60], "job": "Director", "department": "Directing"},
            {**people[(i + 7) % 60], "job": "Screenplay", "department": "Writing"},
            {**people[(i + 9) % 60], "job": "Editor", "department": "Editing"},
        ]
        movie = {
            "id": mid,
            "imdb_id": f"tt{9000000 + i}",
            "title": f"Étoile Uji {i}" if i % 3 == 0 else f"Film Uji {i}",
            "original_title": f"Film Uji {i}",
            "original_language": rnd.choice(["en", "id", "ja", "ko"]),
            "overview": f"Sinopsis bahasa Inggris palsu untuk film {i}.",
            "tagline": f"Tagline {i}",
            "release_date": rel.isoformat(),
            "runtime": 80 + rnd.randint(0, 80),
            "status": "Released",
            "budget": rnd.choice([0, 1_000_000, 25_000_000]),
            "revenue": rnd.choice([0, 5_000_000, 90_000_000]),
            "homepage": "",
            "popularity": round(500 - i * 1.3 + rnd.random(), 3),
            "vote_average": round(rnd.uniform(4, 9), 1),
            "vote_count": rnd.randint(10, 3000),
            "poster_path": f"/poster{mid}.jpg",
            "backdrop_path": f"/backdrop{mid}.jpg",
            "adult": False,
            "genres": [{"id": g, "name": nm} for g, nm in rnd.sample(GENRES, rnd.randint(1, 3))],
            "credits": {"cast": cast, "crew": crew},
            "external_ids": {"imdb_id": f"tt{9000000 + i}",
                             "wikidata_id": f"Q{900000 + i}" if i % 5 else None},
        }
        # cacat yang disengaja
        if i % 17 == 5:
            movie["poster_path"] = None
        if i % 23 == 7:
            movie["overview"] = ""
        if i % 31 == 11:
            movie["runtime"] = 0
        if i % 41 == 13:
            movie["genres"] = []
        if i % 53 == 17:
            movie["credits"]["cast"] = []
        movies[mid] = movie
    return movies


MOVIES = build_movies(150)
STATE = {"calls": 0, "sent_429": False}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_args):  # diam
        pass

    def _send(self, code: int, payload: dict | None = None, headers: dict | None = None):
        body = json.dumps(payload or {}).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        STATE["calls"] += 1
        if STATE["calls"] == 5 and not STATE["sent_429"]:
            STATE["sent_429"] = True
            return self._send(429, {"status_message": "rate limit"}, {"Retry-After": "1"})

        if "Authorization" not in self.headers and "api_key" not in self.path:
            return self._send(401, {"status_message": "no credentials"})

        url = urlparse(self.path)
        qs = {k: v[0] for k, v in parse_qs(url.query).items()}
        parts = url.path.strip("/").split("/")          # ['3', 'discover', 'movie'] atau ['3','movie','1000']

        if parts[1:] == ["discover", "movie"]:
            gte = qs.get("primary_release_date.gte", "0000-00-00")
            lte = qs.get("primary_release_date.lte", "9999-99-99")
            min_votes = int(qs.get("vote_count.gte", 0))
            hits = [m for m in MOVIES.values()
                    if gte <= m["release_date"] <= lte and m["vote_count"] >= min_votes]
            hits.sort(key=lambda m: m["popularity"], reverse=True)
            page = int(qs.get("page", 1))
            chunk = hits[(page - 1) * 20: page * 20]
            return self._send(200, {
                "page": page, "total_results": len(hits), "total_pages": max(1, -(-len(hits) // 20)),
                "results": [{k: m[k] for k in ("id", "title", "release_date", "popularity")} for m in chunk],
            })

        if len(parts) == 3 and parts[1] == "movie":
            m = MOVIES.get(int(parts[2]))
            if not m:
                return self._send(404, {"status_message": "not found"})
            if qs.get("language") == "id-ID":
                has_tr = m["id"] % 10 < 7
                return self._send(200, {
                    "id": m["id"], "title": m["title"],
                    "overview": f"Sinopsis bahasa Indonesia palsu untuk film {m['id']}." if has_tr else "",
                    "tagline": ""})
            out = {k: v for k, v in m.items() if k not in ("credits", "external_ids")}
            wanted = qs.get("append_to_response", "").split(",")
            if "credits" in wanted:
                out["credits"] = m["credits"]
            if "external_ids" in wanted:
                out["external_ids"] = m["external_ids"]
            return self._send(200, out)

        self._send(404, {"status_message": "unknown path"})


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=8099)
    args = ap.parse_args()
    print(f"Mock TMDB aktif di http://127.0.0.1:{args.port}/3  (Ctrl+C untuk berhenti)")
    ThreadingHTTPServer(("127.0.0.1", args.port), Handler).serve_forever()
