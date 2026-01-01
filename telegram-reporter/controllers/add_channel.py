import utils
import json

from lib.api import types, filters, functions, errors
from typing import TYPE_CHECKING
from tortoise.exceptions import IntegrityError

if TYPE_CHECKING:
    import core.client

# @lock
async def AddCahnnel(client: "core.client.Reporter", update: types.Message):
    
    channels: types.Message = await client.parent.input.ask(
        "🤔 What's the link of the channels? \n\nhttps://t.me/username\nhttps://t.me/username",
        client, update.from_user, update.chat, filters.text
    )
    
    usernames = [utils.normalize_username(username) for username in channels.text.split("\n")]
    
    if usernames.__len__() > 10:
        return await client.send_message(update.from_user.id, "⚠️ You are allowed to add a maximum of 10 channels.")
    
    for uname in usernames:
        
        try:
            peer = await client.resolve_peer(uname)
        
        except errors.RPCError as error:
            await client.send_message(update.from_user.id, f"[{error.__class__.__name__}] {error} => {uname}")

        else:
            
            if not isinstance(peer, types.raws.InputPeerChannel):
                await client.send_message(update.from_user.id, f"{uname} is not a channel.")
                continue
            
            channel = await client.invoke(functions.GetFullChannel(channel=peer))
            channel = await types.Chat._parse_full(client, channel)
            
            if (
                channel.available_reactions.reactions is not None 
                or 
                channel.available_reactions.all_are_enabled is True
            ):
                
                reactions = [
                    "❤", "👍", "👎", "🔥", "🥰", "👏", "😁", "🤔", "🤯", "😱", "🤬", "😢", "🎉", "🤩", "🤮", "💩", 
                    "🙏", "👌", "🕊", "🤡", "🥱", "🥴", "😍", "🐳", "❤‍🔥", "🌚", "🌭", "💯", "🤣", "⚡", "🍌", "🏆", 
                    "💔", "🤨", "😐", "🍓", "🍾", "💋", "🖕", "😈", "😴", "😭", "🤓", "👻", "👨‍💻", "👀", "🎃", "🙈",
                    "😇", "😨", "🤝", "✍", "🤗", "🫡", "🎅", "🎄", "☃", "💅", "🤪", "🗿", "🆒", "💘", "🙉", "🦄", 
                    "😘", "💊", "🙊", "😎", "👾", "🤷‍♂", "🤷", "🤷‍♀", "😡"
                ]
                
                try:
                    
                    await client.parent.database.Channel.create(
                        channel_id=channel.id,
                        username=channel.username,
                        reactions=json.dumps(
                            [r.emoji for r in channel.available_reactions.reactions] 
                            if not channel.available_reactions.all_are_enabled else reactions,
                            ensure_ascii=False
                        )
                    )
                    
                except IntegrityError:
                    await client.parent.database.Channel.filter(channel_id=channel.id).update(
                        reactions=json.dumps(
                            [r.emoji for r in channel.available_reactions.reactions] 
                            if not channel.available_reactions.all_are_enabled else reactions,
                            ensure_ascii=False
                        )
                    )
                    
                    await client.send_message(update.from_user.id, f"✅ {uname} updated successfully.")
                
                else:
                    await client.send_message(update.from_user.id, f"✅ {uname} added successfully.")
                
                client.parent.allowed_channels.add(channel.id)