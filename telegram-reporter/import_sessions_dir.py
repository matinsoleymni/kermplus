#!/usr/bin/env python3
from __future__ import annotations

import argparse
import os
import sqlite3
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable


BASE_DIR = Path(__file__).resolve().parent
DEFAULT_SOURCE_DIR = BASE_DIR / "sessions"
DEFAULT_TARGET_DB = Path(os.getenv("TELEGRAM_SESSION_DB", BASE_DIR / "sessions.db"))


@dataclass
class ImportStats:
    inserted: int = 0
    skipped: int = 0
    invalid: int = 0
    total: int = 0


def _ensure_target_schema(connection: sqlite3.Connection) -> None:
    connection.execute("PRAGMA busy_timeout = 5000;")
    connection.execute(
        """
        CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            userid BIGINT NOT NULL UNIQUE,
            string VARCHAR(400) NOT NULL UNIQUE,
            number VARCHAR(15) NOT NULL UNIQUE,
            password VARCHAR(10) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        """
    )
    connection.execute(
        "CREATE INDEX IF NOT EXISTS idx_sessions_userid_aeb893 ON sessions (userid, number);"
    )


def _has_accounts_table(db_path: Path) -> bool:
    try:
        with sqlite3.connect(f"file:{db_path}?mode=ro", uri=True) as conn:
            cursor = conn.execute(
                "SELECT 1 FROM sqlite_master WHERE type='table' AND name='accounts' LIMIT 1;"
            )
            return cursor.fetchone() is not None
    except sqlite3.DatabaseError:
        return False


def _discover_source_databases(source_dir: Path, explicit_db: Path | None) -> list[Path]:
    candidates: list[Path] = []

    if explicit_db:
        candidates.append(explicit_db)
    else:
        preferred = source_dir / "data.db"
        if preferred.exists():
            candidates.append(preferred)

        for db_file in sorted(source_dir.rglob("*.db")):
            if db_file not in candidates:
                candidates.append(db_file)

    unique: list[Path] = []
    seen: set[Path] = set()
    for candidate in candidates:
        resolved = candidate.resolve()
        if resolved in seen:
            continue
        seen.add(resolved)
        if resolved.exists() and _has_accounts_table(resolved):
            unique.append(resolved)

    return unique


def _iter_accounts(db_path: Path) -> Iterable[tuple[str, int, str, str]]:
    with sqlite3.connect(f"file:{db_path}?mode=ro", uri=True) as conn:
        conn.row_factory = sqlite3.Row
        cursor = conn.execute(
            "SELECT session_string, user_id, number, password FROM accounts;"
        )
        for row in cursor:
            yield (
                row["session_string"],
                row["user_id"],
                row["number"],
                row["password"] or "",
            )


def _import_accounts(source_dbs: list[Path], target_db: Path) -> ImportStats:
    stats = ImportStats()

    target_db.parent.mkdir(parents=True, exist_ok=True)
    with sqlite3.connect(target_db) as target:
        _ensure_target_schema(target)

        for source in source_dbs:
            for session_string, user_id, number, password in _iter_accounts(source):
                stats.total += 1

                if not session_string or user_id is None or not number:
                    stats.invalid += 1
                    continue

                try:
                    before = target.total_changes
                    target.execute(
                        """
                        INSERT OR IGNORE INTO sessions (userid, string, number, password)
                        VALUES (?, ?, ?, ?);
                        """,
                        (
                            int(user_id),
                            str(session_string),
                            str(number),
                            str(password),
                        ),
                    )
                    if target.total_changes > before:
                        stats.inserted += 1
                    else:
                        stats.skipped += 1
                except (ValueError, sqlite3.DatabaseError):
                    stats.invalid += 1

        target.commit()

    return stats


def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description=(
            "Import Telegram session accounts from a local folder (accounts table) "
            "into telegram-reporter sessions.db."
        )
    )
    parser.add_argument(
        "--source",
        type=Path,
        default=DEFAULT_SOURCE_DIR,
        help=f"Source directory containing data.db/accounts dump (default: {DEFAULT_SOURCE_DIR})",
    )
    parser.add_argument(
        "--source-db",
        type=Path,
        default=None,
        help="Explicit path to a SQLite file that contains the accounts table.",
    )
    parser.add_argument(
        "--target-db",
        type=Path,
        default=DEFAULT_TARGET_DB,
        help=f"Target sessions database (default: {DEFAULT_TARGET_DB})",
    )
    return parser


def main() -> int:
    parser = _build_parser()
    args = parser.parse_args()

    source_dir = args.source.resolve()
    target_db = args.target_db.resolve()
    source_db = args.source_db.resolve() if args.source_db else None

    if source_db is None and not source_dir.exists():
        print(f"[ERROR] Source directory not found: {source_dir}")
        return 1

    source_dbs = _discover_source_databases(source_dir, source_db)
    if not source_dbs:
        print("[ERROR] No valid source database found (missing accounts table).")
        return 1

    print(f"[INFO] Target DB: {target_db}")
    print("[INFO] Source DB(s):")
    for db in source_dbs:
        print(f"  - {db}")

    stats = _import_accounts(source_dbs, target_db)

    print(
        "[DONE] Imported sessions "
        f"(total={stats.total}, inserted={stats.inserted}, skipped={stats.skipped}, invalid={stats.invalid})"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
