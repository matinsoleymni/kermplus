from __future__ import annotations

import os
import secrets
from pathlib import Path
from typing import TYPE_CHECKING

import aiofiles
from tortoise.exceptions import IntegrityError

import config
import utils

if TYPE_CHECKING:
    from app import App


async def import_sessions(app: App, file_name: str, data: bytes) -> dict:
    """Import sessions from a SQLite dump produced by the original bot."""
    if not data:
        return {"added": 0, "skipped": 0, "total": 0, "detail": "Empty file."}

    if len(data) > config.MAX_FILE_SIZE:
        return {
            "added": 0,
            "skipped": 0,
            "total": 0,
            "detail": "Uploaded file is larger than the configured limit.",
        }

    ext = Path(file_name or "").suffix or ".db"
    tmp_name = f"session_{secrets.token_hex(8)}{ext}"
    file_path = config.TMP_DIR / tmp_name

    added, skipped, total = 0, 0, 0

    try:
        async with aiofiles.open(file_path, "wb") as temp:
            await temp.write(data)

        sessions = await utils.extract_accounts(file_path)
        total = len(sessions)

        for session_string, userid, number, password in sessions:
            try:
                await app.database.Session.create(
                    userid=userid,
                    string=session_string,
                    number=number,
                    password=password,
                )
            except IntegrityError:
                skipped += 1
            else:
                added += 1
    finally:
        if os.path.exists(file_path):
            os.remove(file_path)

    if added:
        await app.agent.launcher()

    return {"added": added, "skipped": skipped, "total": total}
