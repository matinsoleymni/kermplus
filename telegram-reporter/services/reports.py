from __future__ import annotations

import ast
import asyncio
import random
from typing import TYPE_CHECKING, Any

from lib.api import InputReasons, errors, functions, types

import utils
from .channels import DEFAULT_REACTIONS, NEGATIVE_REACTIONS, POSITIVE_REACTIONS

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


async def report_account(app: App, username: str, reason: str, comment: str, count: int = 65) -> dict:
    """Report a user/bot account using random selected active sessions concurrently."""
    normalized = utils.normalize_username(username)

    if not normalized or normalized.isnumeric() or len(normalized) > 33:
        return {"reported": 0, "errors": ["Username is not valid."]}

    sessions = _collect_sessions(app)

    # --- انتخاب تصادفی اکانت‌ها بر اساس پارامتر count ---
    target_count = min(count, len(sessions))
    if not target_count:
        return {"reported": 0, "errors": ["No active sessions available."]}

    selected_sessions = random.sample(sessions, target_count)
    reason_obj = _resolve_reason(reason)

    reported = 0
    errors_list: list[str] = []

    # محدودیت همزمانی: حداکثر 15 درخواست همزمان
    semaphore = asyncio.Semaphore(15)

    async def _do_report(session):
        async with semaphore:
            # ایجاد تاخیر رندوم برای جلوگیری از اسپم شدن
            await asyncio.sleep(random.uniform(0.3, 0.7))

            try:
                peer = await session.resolve_peer(normalized)
            except errors.UsernameNotOccupied:
                return False, f"{normalized}: Username not occupied."
            except errors.RPCError as exc:
                return False, f"{normalized}: {exc.__class__.__name__} - {exc}"

            if not isinstance(peer, (types.raws.InputPeerChat, types.raws.InputPeerUser)):
                return False, f"{normalized}: Unsupported peer type {peer.__class__.__name__}."

            try:
                result = await session.invoke(
                    functions.ReportPeer(
                        peer=peer,
                        reason=reason_obj,
                        message=comment,
                    )
                )
            except errors.RPCError as exc:
                return False, f"{normalized}: {exc.__class__.__name__} - {exc}"

            if result is True:
                return True, None
            return False, None

    tasks = [_do_report(s) for s in selected_sessions]
    results = await asyncio.gather(*tasks)

    for success, err in results:
        if success:
            reported += 1
        if err:
            errors_list.append(err)

    return {"reported": reported, "errors": list(set(errors_list))}


async def report_message(
    app: App,
    username: str,
    message_links: list[str] | list[int],
    option: str | bytes,
    comment: str,
    count: int = 65
) -> dict:
    """Report one or more channel/group messages using random selected sessions concurrently."""
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

    # --- انتخاب تصادفی اکانت‌ها بر اساس پارامتر count ---
    target_count = min(count, len(sessions))
    if not target_count:
        return {"reported": 0, "errors": ["No active sessions available."]}

    selected_sessions = random.sample(sessions, target_count)
    option_bytes = _normalize_report_option(option)

    reported = 0
    errors_list: list[str] = []

    semaphore = asyncio.Semaphore(15)

    async def _do_report_msg(session):
        async with semaphore:
            await asyncio.sleep(random.uniform(0.3, 0.7))

            try:
                peer = await session.resolve_peer(normalized)
            except errors.UsernameNotOccupied:
                return False, f"{normalized}: Username not occupied."
            except errors.RPCError as exc:
                return False, f"{normalized}: {exc.__class__.__name__} - {exc}"

            if not isinstance(
                peer,
                (
                    types.raws.InputPeerChannel,
                    types.raws.InputPeerChannelFromMessage,
                    types.raws.InputPeerChat,
                ),
            ):
                return False, f"{normalized}: Unsupported peer type {peer.__class__.__name__}."

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
                return False, f"{normalized}: {exc.__class__.__name__} - {exc}"

            if isinstance(result, types.raws.ReportResultReported):
                return True, None
            return False, None

    tasks = [_do_report_msg(s) for s in selected_sessions]
    results = await asyncio.gather(*tasks)

    for success, err in results:
        if success:
            reported += 1
        if err:
            errors_list.append(err)

    return {"reported": reported, "errors": list(set(errors_list))}


async def send_reactions(
    app: App,
    link: str,
    emoji: str | None = None,
    mix_negative: bool = False,
    mix_positive: bool = False,
    count: int = 65
) -> dict:
    """Send reactions to a specific post in a channel using random selected sessions concurrently."""
    try:
        username, message_id = utils.normalize_post_link(link)
    except ValueError:
        return {"sent": 0, "errors": ["Invalid post link."], "available_reactions": []}

    sessions = _collect_sessions(app)

    if not sessions:
        return {"sent": 0, "errors": ["No active sessions available."], "available_reactions": []}

    try:
        await app.bot.get_chat(username)
        peer = await app.bot.resolve_peer(username)

    except (errors.RPCError, KeyError) as exc:
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

    if mix_negative:
        selected_reactions = [reaction for reaction in NEGATIVE_REACTIONS if reaction in available]
        if not selected_reactions:
            return {
                "sent": 0,
                "errors": ["None of the selected negative reactions are allowed for this channel."],
                "available_reactions": available,
            }
    elif mix_positive:
        selected_reactions = [reaction for reaction in POSITIVE_REACTIONS if reaction in available]
        if not selected_reactions:
            return {
                "sent": 0,
                "errors": ["None of the selected positive reactions are allowed for this channel."],
                "available_reactions": available,
            }
    elif emoji:
        if emoji not in available:
            return {
                "sent": 0,
                "errors": [f"Reaction {emoji} is not allowed for this channel."],
                "available_reactions": available,
            }
        selected_reactions = [emoji]
    else:
        selected_reactions = random.sample(available, k=min(10, len(available)))

    target_count = min(count, len(sessions))
    if not target_count:
        return {"sent": 0, "errors": ["No active sessions available."], "available_reactions": available}

    selected_sessions = random.sample(sessions, target_count)

    sent = 0
    errors_list: list[str] = []

    semaphore = asyncio.Semaphore(15)

    async def _do_react(session):
        if not session.is_connected:
            return False, None

        selected = random.choice(selected_reactions)

        async with semaphore:
            await asyncio.sleep(random.uniform(0.2, 0.5))
            try:
                result = await session.send_reaction(
                    chat_id="@" + username,
                    message_id=message_id,
                    emoji=selected,
                )
            except errors.UsernameNotOccupied:
                return False, f"{username}: Username not occupied."
            except errors.RPCError as exc:
                return False, f"{username}: {exc.__class__.__name__} - {exc}"

            if result:
                return True, None
            return False, None

    tasks = [_do_react(s) for s in selected_sessions]
    results = await asyncio.gather(*tasks)

    for success, err in results:
        if success:
            sent += 1
        if err:
            errors_list.append(err)

    return {
        "sent": sent,
        "errors": list(set(errors_list)),
        "available_reactions": available,
        "used_reactions": selected_reactions,
        "used_reaction": selected_reactions[0] if len(selected_reactions) == 1 else None,
    }
