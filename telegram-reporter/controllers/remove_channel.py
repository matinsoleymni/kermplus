import utils
import json

from lib.api import types, filters, functions
from typing import TYPE_CHECKING
from tortoise.exceptions import IntegrityError

if TYPE_CHECKING:
    import core.client

# @lock
async def RemoveCahnnel(client: "core.client.Reporter", update: types.Message):
    
    channels: types.Message = await client.parent.input.ask(
        "🤔 What's the link of the channels? \n\nhttps://t.me/username\nhttps://t.me/username",
        client, update.from_user, update.chat, filters.text
    )
    
    usernames = [u.removeprefix("@") for u in [utils.normalize_username(username) for username in channels.text.split("\n")] if u]
    
    if not usernames:
        return None
    
    for q in await client.parent.database.Channel.filter(username__in=usernames).all():
        await q.delete()
        client.parent.allowed_channels.discard(q.channel_id)
    
    await client.send_message(update.from_user.id, "✅ Deleted successfully.")
    