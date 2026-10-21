"""Konfigurasi dan klien HTTP bersama untuk pipeline data CINÉLOG."""
from __future__ import annotations

import logging
import os
import sys
import time
from pathlib import Path

import requests
from dotenv import load_dotenv

ROOT = Path(__file__).resolve().parent.parent
load_dotenv(ROOT / "pipeline" / ".env")

DATA_DIR = ROOT / "data"
RAW_DIR = DATA_DIR / "raw"
CLEAN_DIR = DATA_DIR / "clean"
LOG_DIR = DATA_DIR / "logs"
for _d in (RAW_DIR, CLEAN_DIR, LOG_DIR):
    _d.mkdir(parents=True, exist_ok=True)

TMDB_BASE_URL = os.getenv("TMDB_BASE_URL", "https://api.themoviedb.org/3").rstrip("/")
USER_AGENT = "CinelogStudentProject/0.1 (tugas besar Web Semantik & MSBD)"


def setup_logging(name: str) -> logging.Logger:
    """Log ke layar dan ke data/logs/<name>.log."""
    logger = logging.getLogger(name)
    if logger.handlers:
        return logger
    logger.setLevel(logging.INFO)
    fmt = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s", "%H:%M:%S")
    for handler in (logging.StreamHandler(sys.stdout),
                    logging.FileHandler(LOG_DIR / f"{name}.log", encoding="utf-8")):
        handler.setFormatter(fmt)
        logger.addHandler(handler)
    return logger


class TmdbClient:
    """Klien TMDB v3 dengan jeda antar request, retry, dan penanganan 429/5xx."""

    def __init__(self, logger: logging.Logger, max_attempts: int = 6):
        self.log = logger
        self.max_attempts = max_attempts
        self.min_interval = float(os.getenv("TMDB_MIN_INTERVAL", "0.12"))
        self._last_call = 0.0
        self.session = requests.Session()
        self.session.headers.update({"Accept": "application/json", "User-Agent": USER_AGENT})

        token = os.getenv("TMDB_READ_TOKEN", "").strip()
        self.api_key = os.getenv("TMDB_API_KEY", "").strip()
        if token:
            self.session.headers["Authorization"] = f"Bearer {token}"
        elif not self.api_key:
            raise SystemExit("TMDB_READ_TOKEN atau TMDB_API_KEY belum diisi di pipeline/.env")

    def get(self, path: str, **params):
        """GET JSON. Mengembalikan None jika 404. Menghentikan program jika kredensial salah."""
        url = f"{TMDB_BASE_URL}/{path.lstrip('/')}"
        if self.api_key and "Authorization" not in self.session.headers:
            params["api_key"] = self.api_key

        for attempt in range(1, self.max_attempts + 1):
            wait = self.min_interval - (time.monotonic() - self._last_call)
            if wait > 0:
                time.sleep(wait)
            try:
                resp = self.session.get(url, params=params, timeout=20)
                self._last_call = time.monotonic()
            except (requests.ConnectionError, requests.Timeout) as exc:
                self._last_call = time.monotonic()
                delay = min(2 ** attempt, 30)
                self.log.warning("Jaringan gagal (%s). Ulangi %d/%d dalam %ss", exc.__class__.__name__,
                                 attempt, self.max_attempts, delay)
                time.sleep(delay)
                continue

            if resp.status_code == 200:
                return resp.json()
            if resp.status_code == 404:
                return None
            if resp.status_code == 401:
                raise SystemExit("TMDB menolak kredensial (401). Periksa token/API key di pipeline/.env")
            if resp.status_code == 429 or resp.status_code >= 500:
                try:
                    delay = float(resp.headers.get("Retry-After", ""))
                except ValueError:
                    delay = min(2 ** attempt, 30)
                self.log.warning("HTTP %s pada %s. Ulangi %d/%d dalam %ss", resp.status_code, path,
                                 attempt, self.max_attempts, delay)
                time.sleep(delay)
                continue
            resp.raise_for_status()

        self.log.error("Menyerah setelah %d percobaan: %s", self.max_attempts, path)
        return None
