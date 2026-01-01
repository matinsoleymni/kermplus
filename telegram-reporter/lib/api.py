from pyrogram import filters
from pyrogram.client import Client
from pyrogram.methods.utilities.idle import idle

class handlers:
    __slots__ = []
    
    from pyrogram.handlers.handler import Handler
    from pyrogram.handlers.callback_query_handler import CallbackQueryHandler
    from pyrogram.handlers.message_handler import MessageHandler

class types:
    __slots__ = []
    
    from pyrogram.types.update import Update
    from pyrogram.types.messages_and_media.document import Document
    from pyrogram.types.messages_and_media.message import Message
    from pyrogram.types.bots_and_keyboards.callback_query import CallbackQuery
    from pyrogram.types.user_and_chats.user import User
    from pyrogram.types.user_and_chats.chat import Chat
    from pyrogram.types.bots_and_keyboards.inline_keyboard_button import InlineKeyboardButton
    from pyrogram.types.bots_and_keyboards.inline_keyboard_markup import InlineKeyboardMarkup
    from pyrogram.types.messages_and_media.link_preview_options import LinkPreviewOptions
    
    class raws:
        __slots__ = []
        from pyrogram.raw.types.message_report_option import MessageReportOption
        from pyrogram.raw.types.report_result_reported import ReportResultReported
        from pyrogram.raw.types.report_result_add_comment import ReportResultAddComment
        
        # Peers
        from pyrogram.raw.types.input_peer_channel import InputPeerChannel
        from pyrogram.raw.types.input_peer_channel_from_message import InputPeerChannelFromMessage
        from pyrogram.raw.types.input_peer_chat import InputPeerChat
        from pyrogram.raw.types.input_peer_empty import InputPeerEmpty
        from pyrogram.raw.types.input_peer_self import InputPeerSelf
        from pyrogram.raw.types.input_peer_user import InputPeerUser
        from pyrogram.raw.types.input_peer_user_from_message import InputPeerUserFromMessage
        
class errors:
    __slots__ = []

    from pyrogram.errors.rpc_error import RPCError
    from pyrogram.errors.exceptions.flood_420 import FloodWait, FrozenMethodInvalid

    from pyrogram.errors.exceptions.unauthorized_401 import (
        UserDeactivated, SessionRevoked, UserDeactivatedBan, SessionExpired
    )
    
    from pyrogram.errors.exceptions.bad_request_400 import UsernameNotOccupied

class functions:
    __slots__ = []
    
    from pyrogram.raw.functions.account.report_peer import ReportPeer
    from pyrogram.raw.functions.messages.report import Report as ReportMessage
    from pyrogram.raw.functions.updates.get_state import GetState
    from pyrogram.raw.functions.channels.get_full_channel import GetFullChannel

class InputReasons:
    __slots__ = []
    
    from pyrogram.raw.types.input_report_reason_personal_details import InputReportReasonPersonalDetails
    from pyrogram.raw.types.input_report_reason_geo_irrelevant import InputReportReasonGeoIrrelevant
    from pyrogram.raw.types.input_report_reason_illegal_drugs import InputReportReasonIllegalDrugs
    from pyrogram.raw.types.input_report_reason_child_abuse import InputReportReasonChildAbuse
    from pyrogram.raw.types.input_report_reason_pornography import InputReportReasonPornography
    from pyrogram.raw.types.input_report_reason_copyright import InputReportReasonCopyright
    from pyrogram.raw.types.input_report_reason_violence import InputReportReasonViolence
    from pyrogram.raw.types.input_report_reason_other import InputReportReasonOther
    from pyrogram.raw.types.input_report_reason_fake import InputReportReasonFake
    from pyrogram.raw.types.input_report_reason_spam import InputReportReasonSpam
    
    