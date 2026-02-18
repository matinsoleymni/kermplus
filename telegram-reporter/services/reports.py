from __future__ import annotations

import ast
import asyncio
import random
from typing import TYPE_CHECKING, Any

from lib.api import InputReasons, errors, functions, types

import utils
from .channels import DEFAULT_REACTIONS

if TYPE_CHECKING:
    from app import App


def _collect_sessions(app: App) -> list[Any]:
    non_flooded = app.agent.get_non_flooded_sessions()
    flooded = [
        session
        for session in app.agent.get_sessions_ordered_by_flood_time()
        if (session.flood.time // 60) < 3
    ]
    return [*non_flooded, *flooded]


def _normalize_report_option(option: str | bytes) -> bytes:
    if isinstance(option, bytes):
        return option

    value = str(option).strip()

    if not value:
        raise ValueError("Report option cannot be empty.")

    if value.startswith("b'") or value.startswith('b"'):
        try:
            literal = ast.literal_eval(value)
        except (SyntaxError, ValueError):
            literal = None
        else:
            if isinstance(literal, bytes):
                return literal

    return value.encode()


def _resolve_reason(reason: str):
    mapping = {
        "personal_details": InputReasons.InputReportReasonPersonalDetails,
        "geo_irrelevant": InputReasons.InputReportReasonGeoIrrelevant,
        "illegal_drugs": InputReasons.InputReportReasonIllegalDrugs,
        "child_abuse": InputReasons.InputReportReasonChildAbuse,
        "pornography": InputReasons.InputReportReasonPornography,
        "copyright": InputReasons.InputReportReasonCopyright,
        "violence": InputReasons.InputReportReasonViolence,
        "other": InputReasons.InputReportReasonOther,
        "fake": InputReasons.InputReportReasonFake,
        "spam": InputReasons.InputReportReasonSpam,
    }

    if hasattr(InputReasons, reason):
        return getattr(InputReasons, reason)()

    reason_cls = mapping.get(reason.lower())
    if not reason_cls:
        raise ValueError(f"Unsupported reason: {reason}")

    return reason_cls()


async def report_account(app: App, username: str, reason: str, comment: str) -> dict:
    """Report a user/bot account using all active sessions."""
    normalized = utils.normalize_username(username)

    if not normalized or normalized.isnumeric() or len(normalized) > 33:
        return {"reported": 0, "errors": ["Username is not valid."]}

    sessions = _collect_sessions(app)
    if not sessions:
        return {"reported": 0, "errors": ["No active sessions available."]}

    reason_obj = _resolve_reason(reason)

    reported = 0
    errors_list: list[str] = []

    for session in sessions:
        try:
            peer = await session.resolve_peer(normalized)
        except errors.UsernameNotOccupied:
            errors_list.append(f"{normalized}: Username not occupied.")
            continue
        except errors.RPCError as exc:
            errors_list.append(f"{normalized}: {exc.__class__.__name__} - {exc}")
            continue

        if not isinstance(peer, (types.raws.InputPeerChat, types.raws.InputPeerUser)):
            errors_list.append(f"{normalized}: Unsupported peer type {peer.__class__.__name__}.")
            continue

        try:
            result = await session.invoke(
                functions.ReportPeer(
                    peer=peer,
                    reason=reason_obj,
                    message=comment,
                )
            )
        except errors.RPCError as exc:
            errors_list.append(f"{normalized}: {exc.__class__.__name__} - {exc}")
            continue

        if result is True:
            reported += 1

    return {"reported": reported, "errors": errors_list}


async def report_message(
    app: App,
    username: str,
    message_links: list[str] | list[int],
    option: str | bytes,
    comment: str,
) -> dict:
    """Report one or more channel/group messages."""
    normalized = utils.normalize_username(username)

    if not normalized or normalized.isnumeric() or len(normalized) > 33:
        return {"reported": 0, "errors": ["Username is not valid."]}

    message_ids: list[int] = []
    for item in message_links:
        if isinstance(item, int):
            message_ids.append(item)
            continue

        link = str(item).strip()
        if not link:
            continue

        message_id = link.split("/")[-1]
        if message_id.isnumeric():
            message_ids.append(int(message_id))

    message_ids = sorted(set(message_ids))

    if not message_ids:
        return {"reported": 0, "errors": ["No valid message identifiers supplied."]}

    sessions = _collect_sessions(app)
    if not sessions:
        return {"reported": 0, "errors": ["No active sessions available."]}

    option_bytes = _normalize_report_option(option)

    reported = 0
    errors_list: list[str] = []

    for session in sessions:
        try:
            peer = await session.resolve_peer(normalized)
        except errors.UsernameNotOccupied:
            errors_list.append(f"{normalized}: Username not occupied.")
            continue
        except errors.RPCError as exc:
            errors_list.append(f"{normalized}: {exc.__class__.__name__} - {exc}")
            continue

        if not isinstance(
            peer,
            (
                types.raws.InputPeerChannel,
                types.raws.InputPeerChannelFromMessage,
                types.raws.InputPeerChat,
            ),
        ):
            errors_list.append(f"{normalized}: Unsupported peer type {peer.__class__.__name__}.")
            continue

        try:
            result = await session.invoke(
                functions.ReportMessage(
                    peer=peer,
                    id=message_ids,
                    option=option_bytes,
                    message=comment,
                )
            )
            if isinstance(result, types.raws.ReportResultAddComment):
                result = await session.invoke(
                    functions.ReportMessage(
                        peer=peer,
                        id=message_ids,
                        option=result.option,
                        message=comment,
                    )
                )
        except errors.RPCError as exc:
            errors_list.append(f"{normalized}: {exc.__class__.__name__} - {exc}")
            continue

        if isinstance(result, types.raws.ReportResultReported):
            reported += 1

    return {"reported": reported, "errors": errors_list}


async def send_reactions(app: App, link: str, emoji: str | None = None) -> dict:
    """Send reactions to a specific post in a channel using available sessions."""
    try:
        username, message_id = utils.normalize_post_link(link)
    except ValueError:
        return {"sent": 0, "errors": ["Invalid post link."], "available_reactions": []}

    sessions = _collect_sessions(app)
    if not sessions:
        return {"sent": 0, "errors": ["No active sessions available."], "available_reactions": []}

    try:
        peer = await app.bot.resolve_peer(username)
    except errors.RPCError as exc:
        return {
            "sent": 0,
            "errors": [f"{username}: {exc.__class__.__name__} - {exc}"],
            "available_reactions": [],
        }

    if not isinstance(peer, types.raws.InputPeerChannel):
        return {"sent": 0, "errors": [f"{username} is not a channel."], "available_reactions": []}

    channel = await app.bot.invoke(functions.GetFullChannel(channel=peer))
    channel = await types.Chat._parse_full(app.bot, channel)

    if not channel or not channel.available_reactions:
        return {
            "sent": 0,
            "errors": ["Channel reactions are disabled."],
            "available_reactions": [],
        }

    available = (
        DEFAULT_REACTIONS
        if channel.available_reactions.all_are_enabled
        else [r.emoji for r in channel.available_reactions.reactions or []]
    )

    if not available:
        return {
            "sent": 0,
            "errors": ["Channel reactions are disabled."],
            "available_reactions": [],
        }

    if emoji:
        if emoji not in available:
            return {
                "sent": 0,
                "errors": [f"Reaction {emoji} is not allowed for this channel."],
                "available_reactions": available,
            }
        selected_reactions = [emoji]
    else:
        selected_reactions = random.sample(available, k=min(10, len(available)))

    sent = 0
    errors_list: list[str] = []

    for session in sessions:
        if not session.is_connected:
            continue

        selected = random.choice(selected_reactions)

        try:
            result = await session.send_reaction(
                chat_id="@" + username,
                message_id=message_id,
                emoji=selected,
            )
        except errors.UsernameNotOccupied:
            errors_list.append(f"{username}: Username not occupied.")
            continue
        except errors.RPCError as exc:
            errors_list.append(f"{username}: {exc.__class__.__name__} - {exc}")
            continue

        if result:
            sent += 1

        if sent and not (sent % 3):
            await asyncio.sleep(1)

    return {
        "sent": sent,
        "errors": errors_list,
        "available_reactions": available,
        "used_reactions": selected_reactions,
        "used_reaction": selected_reactions[0] if len(selected_reactions) == 1 else None,
    }
