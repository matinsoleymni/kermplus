from __future__ import annotations

import json
from typing import TYPE_CHECKING

from tortoise.exceptions import IntegrityError

import utils
from lib.api import errors, functions, types

if TYPE_CHECKING:
    from app import App

DEFAULT_REACTIONS = [
    "❤", "👍", "👎", "🔥", "🥰", "👏", "😁", "🤔", "🤯", "😱", "🤬", "😢", "🎉", "🤩", "🤮",
    "💩", "🙏", "👌", "🕊", "🤡", "🥱", "🥴", "😍", "🐳", "❤‍🔥", "🌚", "🌭", "💯", "🤣", "⚡",
    "🍌", "🏆", "💔", "🤨", "😐", "🍓", "🍾", "💋", "🖕", "😈", "😴", "😭", "🤓", "👻", "👨‍💻",
    "👀", "🎃", "🙈", "😇", "😨", "🤝", "✍", "🤗", "🫡", "🎅", "🎄", "☃", "💅", "🤪", "🗿",
    "🆒", "💘", "🙉", "🦄", "😘", "💊", "🙊", "😎", "👾", "🤷‍♂", "🤷", "🤷‍♀", "😡",
]

NEGATIVE_REACTIONS = [
    "🖕", "💔", "👎", "😢", "💩", "🤮", "🤬", "😡", "🥱", "🍌", "😈",
]


async def add_channels(app: App, links: list[str]) -> dict:
    """Add or update channels and cache their available reactions."""
    normalized = [
        utils.normalize_username(link) for link in links if link and utils.normalize_username(link)
    ]

    if not normalized:
        return {
            "added": [],
            "updated": [],
            "errors": ["No valid channel usernames provided."],
        }

    if len(normalized) > 10:
        return {
            "added": [],
            "updated": [],
            "errors": ["A maximum of 10 channels can be processed at once."],
        }

    results = {"added": [], "updated": [], "errors": []}

    for username in normalized:
        try:
            peer = await app.bot.resolve_peer(username)
        except errors.RPCError as exc:
            results["errors"].append(f"{username}: {exc.__class__.__name__} - {exc}")
            continue

        if not isinstance(peer, types.raws.InputPeerChannel):
            results["errors"].append(f"{username} is not a channel.")
            continue

        channel = await app.bot.invoke(functions.GetFullChannel(channel=peer))
        channel = await types.Chat._parse_full(app.bot, channel)

        if not channel.available_reactions:
            results["errors"].append(f"{username}: channel does not expose reactions.")
            continue

        if (
            channel.available_reactions.reactions is None
            and channel.available_reactions.all_are_enabled is not True
        ):
            results["errors"].append(f"{username}: channel does not expose reactions.")
            continue

        reactions = (
            [r.emoji for r in channel.available_reactions.reactions]
            if not channel.available_reactions.all_are_enabled
            else DEFAULT_REACTIONS
        )

        payload = json.dumps(reactions, ensure_ascii=False)

        try:
            await app.database.Channel.create(
                channel_id=channel.id,
                username=channel.username,
                reactions=payload,
            )
        except IntegrityError:
            await app.database.Channel.filter(channel_id=channel.id).update(reactions=payload)
            results["updated"].append(username)
        else:
            results["added"].append(username)

        app.allowed_channels.add(channel.id)

    return results


async def remove_channels(app: App, links: list[str]) -> dict:
    """Remove channels from cache and database."""
    normalized = [
        utils.normalize_username(link).removeprefix("@")
        for link in links
        if link and utils.normalize_username(link)
    ]

    if not normalized:
        return {"removed": 0}

    records = await app.database.Channel.filter(username__in=normalized).all()
    for record in records:
        await record.delete()
        app.allowed_channels.discard(record.channel_id)

    return {"removed": len(records)}
