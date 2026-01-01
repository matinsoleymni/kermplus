import random
import asyncio
import utils

from lib.api import types, errors, filters, functions
from typing import TYPE_CHECKING
from itertools import cycle

if TYPE_CHECKING:
    import core.client

async def Reaction(client: "core.client.Reporter", update: types.Message):
    
    post_link_asked: types.Message = await client.parent.input.ask(
        "🤔 What's the link of the post? (only one post) \n\nhttps://t.me/username/5454",
        client, update.from_user, update.chat, filters.text
    )
    
    if not post_link_asked:
        return None
    
    try:
        username, post_id = utils.normalize_post_link(post_link_asked.text)
    
    except ValueError:
        return 
    
    non_flooded = client.parent.agent.get_non_flooded_sessions()
    flooded = [session for session in client.parent.agent.get_sessions_ordered_by_flood_time() if (session.flood.time // 60) < 3]
    sessions = [*non_flooded, *flooded]
    channel = None
    
    if not sessions:
        return await client.send_message(update.from_user.id, "⚠️ There is no active session.")
    
    try:
        peer = await client.resolve_peer(username)
    
    except errors.RPCError as error:
        return await client.send_message(update.from_user.id, f"[{error.__class__.__name__}] {error} => {username}")

    else:
        if not isinstance(peer, types.raws.InputPeerChannel):
            return await client.send_message(update.from_user.id, f"{username} is not a channel.")
        
        channel = await client.invoke(functions.GetFullChannel(channel=peer))
        channel = await types.Chat._parse_full(client, channel)
    
    if not channel:
        return await client.send_message(update.from_user.id, "❌ Something is wrong.")
    
    if channel.available_reactions.reactions is None:
        return await client.send_message(update.from_user.id, "⚠️ The channel does not have active reactions.")
    
    reactions = [
        "❤", "👍", "👎", "🔥", "🥰", "👏", "😁", "🤔", "🤯", "😱", "🤬", "😢", "🎉", "🤩", "🤮", "💩", 
        "🙏", "👌", "🕊", "🤡", "🥱", "🥴", "😍", "🐳", "❤‍🔥", "🌚", "🌭", "💯", "🤣", "⚡", "🍌", "🏆", 
        "💔", "🤨", "😐", "🍓", "🍾", "💋", "🖕", "😈", "😴", "😭", "🤓", "👻", "👨‍💻", "👀", "🎃", "🙈",
        "😇", "😨", "🤝", "✍", "🤗", "🫡", "🎅", "🎄", "☃", "💅", "🤪", "🗿", "🆒", "💘", "🙉", "🦄", 
        "😘", "💊", "🙊", "😎", "👾", "🤷‍♂", "🤷", "🤷‍♀", "😡"
    ] if (
        channel.available_reactions.all_are_enabled is True
    ) else [r.emoji for r in channel.available_reactions.reactions]
    
    reaction = None
    
    if reactions.__len__() > 1:
        splited_reactions = [reactions[i : i + 4] for i in range(0, len(reactions), 4)]
        
        reaction_asked: types.CallbackQuery = await client.parent.input.ask(
            "🤔 Which reaction?",
            client, update.from_user, update.chat, None,
            reply_markup=types.InlineKeyboardMarkup(
                [
                    [
                        types.InlineKeyboardButton(
                            text=reaction,
                            callback_data=reaction
                        ) for reaction in reactions
                    ] for reactions in splited_reactions
                ]
            )
        )
        
        reaction = reaction_asked.data
    
    else:
        reaction = reactions[0]
    
    if not reaction:
        return
    
    await client.send_message(update.from_user.id, f"♻️ The request has been successfully added to the queue for (https://t.me/{username}/{post_id}) and you will be notified of the result.")
    
    delay = cycle([i for i in range(1, 5)])
    counter = 0
        
    for session in sessions:
        
        try:
            result = await session.send_reaction(
                chat_id="@" + username,
                message_id=post_id,
                emoji=reaction
            )
            
            if result:
                counter += 1
        
        except errors.UsernameNotOccupied:
            ...
        
        if counter != 0 and counter % 3 == 0:
            await asyncio.sleep(delay.__next__())
    
    await client.send_message(update.from_user.id, f"✅ The reaction sending process was successful (https://t.me/{username}/{post_id}).")
            
            