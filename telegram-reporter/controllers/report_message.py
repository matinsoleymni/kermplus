import utils
import asyncio
import config

from lib.api import types, functions, errors, Client, filters
from typing import TYPE_CHECKING
from .base import lock

if TYPE_CHECKING:
    import core.client

# @lock
async def ReportMessage(client: "core.client.Reporter", update: types.Message):
    
    username_asked: types.Message = await client.parent.input.ask(
        "📍 Send channel ID or Group ID: \n\n@username\nhttps://t.me/username",
        client, update.from_user, update.chat, filters.text
    )
    
    if not username_asked or username_asked.text == "/cancel":
        return None
    
    username = utils.normalize_username(username_asked.text)
    
    if not username or username.isnumeric() or username.__len__() > 33:
        return await client.send_message(update.from_user.id, "❌ Username is not valid.")
    
    
    comment_asked: types.Message = await client.parent.input.ask(
        "📜 Write a comment? ",
        client, update.from_user, update.chat, filters.text
    )
    
    if not comment_asked:
        return None
    
    comment = comment_asked.text

    option_asked: types.CallbackQuery = await client.parent.input.ask(
        "🤔 What is wrong with this message?",
        client, update.from_user, update.chat, None,
        reply_markup=types.InlineKeyboardMarkup(
            [
                [
                    types.InlineKeyboardButton(
                        text=option["text"],
                        callback_data=option["option"]
                    )
                ] for option in config.OPTIONS
            ]
        )
    )
    
    if not option_asked:
        return None
    
    option = option_asked.data.strip()
    
    other_option = (c := [opt for opt in config.OPTIONS if opt["option"] == option]) and c[0]
    
    if other_option and "other" in other_option:
        await option_asked.message.delete()
        
        option_asked: types.CallbackQuery = await client.parent.input.ask(
            "🤔 What is wrong with this message?",
            client, update.from_user, update.chat, None,
            reply_markup=types.InlineKeyboardMarkup(
                [
                    [
                        types.InlineKeyboardButton(
                            text=option["text"],
                            callback_data=option["option"]
                        )
                    ] for option in other_option["other"]
                ]
            )
        )
        
        if not other_option:
            return None

        option = option_asked.data.strip()
    
    message_ids_asked: types.Message = await client.parent.input.ask(
        "⚠️ Which messages where wrong? Send the links. \n\nhttps://t.me/username/5454\nhttps://t.me/username/5203",
        client, update.from_user, update.chat, filters.text
    )
    
    if not message_ids_asked:
        return None
    
    msg_ids = list(
        set(
            map(
                int,
                filter(
                    lambda x:x.isnumeric(),
                    [msg.split("/")[-1] for msg in message_ids_asked.text.split("\n")]
                )
            )
        )
    )
    
    if not msg_ids:
        return await client.send_message(update.from_user.id, "❌ The entered messages are not valid.")
    
    message = await client.send_message(
        update.from_user.id,
        "♻️ Starting the process ..."
    )
    
    non_flooded = client.parent.agent.get_non_flooded_sessions()
    flooded = [session for session in client.parent.agent.get_sessions_ordered_by_flood_time() if (session.flood.time // 60) < 3]
    sessions = [*non_flooded, *flooded]
    counter = 0
    
    if not sessions:
        return await client.send_message(update.from_user.id, "⚠️ There is no active session.")
    
    for session in sessions:
        
        try:
            peer = await session.resolve_peer(username)
        
        except errors.UsernameNotOccupied:
            continue
        
        except errors.FrozenMethodInvalid:
            continue
        
        except errors.RPCError as error:
            print(f"[ReportMessage - Resolve Peer - {session.session_id}] ", error)
            continue
        
        if not isinstance(peer, (types.raws.InputPeerChannel, types.raws.InputPeerChannelFromMessage, types.raws.InputPeerChat)):
            return await client.send_message(
                update.from_user.id,
                "⚠️ Peer does not have a valid type ({}).".format(peer.__class__.__name__)
            )
        
        try:
            result = await session.invoke(
                functions.ReportMessage(
                    peer=peer,
                    id=msg_ids,
                    option=option.encode(),
                    message=comment
                )
            )
            
            if isinstance(result, types.raws.ReportResultAddComment):
                result = await session.invoke(
                    functions.ReportMessage(
                        peer=peer,
                        id=msg_ids,
                        option=result.option,
                        message=comment
                    )
                )
                
            if isinstance(result, types.raws.ReportResultReported):
                counter += 1
            
        except errors.FrozenMethodInvalid:
            continue
                
        except errors.RPCError as error:
            print(f"[ReportMessage - {session.session_id}]", error)
            continue
        
        if counter and not (counter % 3):
            await message.edit_text(
                f"♻️ {username} was reported by {counter} session so far."
            )
    
    await client.send_message(update.from_user.id, f"✅ Channel report ({username}) completed successfully (Report {counter}).")
            
        