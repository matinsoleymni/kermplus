
from lib.api import types
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    import core.client

async def Status(client: "core.client.Reporter", update: types.Message):
    
    active_session, available_session, flooded_session, non_flooded_session, is_process_session = await client.parent.agent.statistics()
    channels = await client.parent.database.Channel.all()
    
    await client.send_message(
        update.from_user.id,
        (
            "🔸 Active sessions: {}\n🔸 Available sessions: {}\n🔸 Flooded sessions: {}\n🔸 Non-flooded sessions: {}\n🔸 Total Active Process: {}"
            "\n\n📍 Active Channels: \n{}"
        ).format(
            active_session, available_session, flooded_session, non_flooded_session, is_process_session,
            "" if not channels else "\n".join(["  + @" + ch.username for ch in channels])
        )
    )