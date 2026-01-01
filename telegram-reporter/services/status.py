from __future__ import annotations

from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from app import App


async def get_status(app: App) -> dict:
    active_session, available_session, flooded_session, non_flooded_session, active_process = (
        await app.agent.statistics()
    )
    channels = await app.database.Channel.all()
    return {
        "active_sessions": active_session,
        "available_sessions": available_session,
        "flooded_sessions": flooded_session,
        "non_flooded_sessions": non_flooded_session,
        "active_processes": active_process,
        "channels": [f"@{ch.username}" for ch in channels],
    }
