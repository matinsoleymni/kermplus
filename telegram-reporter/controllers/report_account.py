import utils
import asyncio

from lib.api import types, errors, functions, InputReasons, filters
from typing import TYPE_CHECKING
from .base import lock

if TYPE_CHECKING:
    import core.client

# @lock
async def ReportAccount(client: "core.client.Reporter", update: types.Message):

    username_asked: types.Message = await client.parent.input.ask(
        "📍 Send Bot ID or User ID: \n\n@username\nhttps://t.me/username",
        client, update.from_user, update.chat, filters.text
    )
    
    if not username_asked or username_asked.text == "/cancel":
        return None
    
    username = username_asked.text
    
    if not username or username.isnumeric() or username.__len__() > 33:
        return await client.send_message(update.from_user.id, "❌ Username is not valid.")
    
    username = utils.normalize_username(username_asked.text)
    
    expected_reasons = {
        InputReasons.InputReportReasonPersonalDetails: "Personal Details",
        InputReasons.InputReportReasonGeoIrrelevant: "GeoIrre levant",
        InputReasons.InputReportReasonIllegalDrugs: "Illegal Drugs",
        InputReasons.InputReportReasonChildAbuse: "Child Abuse",
        InputReasons.InputReportReasonPornography: "Pornography",
        InputReasons.InputReportReasonCopyright: "Copyright",
        InputReasons.InputReportReasonViolence: "Violence",
        InputReasons.InputReportReasonOther: "Other",
        InputReasons.InputReportReasonFake: "Fake",
        InputReasons.InputReportReasonSpam: "Spam",
    }
    
    
    
    reason_asked: types.CallbackQuery = await client.parent.input.ask(
        "🤔 What is wrong with this account?",
        client, update.from_user, update.chat, None,
        reply_markup=types.InlineKeyboardMarkup(
            [
                [
                    types.InlineKeyboardButton(
                        text=reason,
                        callback_data=rtype.__name__
                    )
                ] for rtype, reason in expected_reasons.items()
            ]
        )
    )
    
    if not reason_asked:
        return None
    
    reason = reason_asked.data
    
    comment_asked: types.Message = await client.parent.input.ask(
        "📜 Write a comment? ",
        client, update.from_user, update.chat, filters.text
    )
    
    if not comment_asked:
        return None
    
    comment = comment_asked.text
    
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
            print(f"[ReportAccount - Resolve Peer - {session.session_id}] ", error)
            continue
        
        if not isinstance(peer, (types.raws.InputPeerChat, types.raws.InputPeerUser)):
            return await client.send_message(
                update.from_user.id,
                "⚠️ Peer does not have a valid type ({}).".format(peer.__class__.__name__)
            )
        
        try:
            result = await session.invoke(
                functions.ReportPeer(
                    peer=peer,
                    reason=getattr(InputReasons, reason)(),
                    message=comment
                )
            )
            
            if result is True:
                counter += 1
        
        except errors.FrozenMethodInvalid:
            continue
        
        except errors.RPCError as error:
            print(f"[ReportAccount - {session.session_id}] ", error)
            
        if counter and not (counter % 3):
            await message.edit_text(
                f"♻️ {username} was reported by {counter} session so far."
            )
    
    await client.send_message(update.from_user.id, f"✅ The account report ({username}) was completed successfully (Report {counter}).")
    
        
        
        