"""Isi web/.env (milik Laravel) dari .env di root proyek, supaya tidak perlu mengedit manual.

Yang diatur: koneksi MariaDB (host 'mariadb', port 3306 = alamat DARI DALAM Docker), driver
session/cache/queue = file (agar Laravel tidak butuh tabel tambahan), dan alamat Fuseki.
Aman dijalankan berulang kali. Password tidak pernah dicetak ke layar.

Pemakaian (dari folder root proyek):
    python scripts\\configure_laravel_env.py
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path


def read_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8-sig").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, val = line.partition("=")
        val = val.strip()
        if len(val) >= 2 and val[0] == val[-1] and val[0] in "\"'":
            val = val[1:-1]
        values[key.strip()] = val
    return values


def quote(value: str) -> str:
    """Tulis nilai agar aman dibaca dotenv Laravel (spasi, #, $, kutip)."""
    if re.fullmatch(r"[A-Za-z0-9_./:@+-]*", value):
        return value
    escaped = value.replace("\\", "\\\\").replace('"', '\\"').replace("$", "\\$")
    return f'"{escaped}"'


def set_key(lines: list[str], key: str, value: str) -> None:
    """Ganti baris KEY=... (utamakan yang aktif, lalu yang di-komentari); tambahkan jika belum ada."""
    pattern = re.compile(rf"^\s*(#\s*)?{re.escape(key)}\s*=")
    active = commented = None
    for i, line in enumerate(lines):
        m = pattern.match(line)
        if not m:
            continue
        if m.group(1) is None and active is None:
            active = i
        elif m.group(1) is not None and commented is None:
            commented = i
    new = f"{key}={quote(value)}"
    idx = active if active is not None else commented
    if idx is None:
        lines.append(new)
    else:
        lines[idx] = new


def main() -> None:
    root = Path(__file__).resolve().parent.parent
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--root", default=str(root), help="folder root proyek")
    args = ap.parse_args()
    root = Path(args.root)

    root_env, web_env = root / ".env", root / "web" / ".env"
    if not root_env.exists():
        sys.exit(f"{root_env} tidak ada. Salin dari .env.example dulu.")
    if not web_env.exists():
        sys.exit(f"{web_env} tidak ada. Laravel belum terpasang atau belum membuat .env "
                 f"(jalankan: copy web\\.env.example web\\.env).")

    src = read_env(root_env)
    for needed in ("DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"):
        if not src.get(needed):
            sys.exit(f"{needed} kosong di {root_env}.")

    settings = {
        "APP_NAME": "CINELOG",
        "APP_URL": "http://localhost:8000",
        "DB_CONNECTION": "mysql",
        "DB_HOST": "mariadb",
        "DB_PORT": "3306",
        "DB_DATABASE": src["DB_DATABASE"],
        "DB_USERNAME": src["DB_USERNAME"],
        "DB_PASSWORD": src["DB_PASSWORD"],
        "SESSION_DRIVER": "file",
        "CACHE_STORE": "file",
        "QUEUE_CONNECTION": "sync",
        "FUSEKI_URL": "http://fuseki:3030/cinelog",
        "CINELOG_BASE_URI": "http://localhost:8000/",
    }

    lines = web_env.read_text(encoding="utf-8").splitlines()
    for key, value in settings.items():
        set_key(lines, key, value)
    web_env.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(f"web/.env diperbarui ({len(settings)} pengaturan). Password tidak ditampilkan.")
    for key, value in settings.items():
        shown = "********" if key == "DB_PASSWORD" else value
        print(f"  {key}={shown}")


if __name__ == "__main__":
    main()
