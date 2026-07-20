async def get_chat_info(app, link: str) -> dict:
    try:
        clean_link = link.replace("https://t.me/", "") \
                         .replace("http://t.me/", "") \
                         .replace("t.me/", "") \
                         .replace("@", "") \
                         .split("?")[0] \
                         .strip()

        if clean_link.lstrip('-').isdigit():
            clean_link = int(clean_link)

        client = app.bot

        if not getattr(client, "is_connected", False):
            connected_agents = [
                agent for agent in app.agent.sessions.values()
                if getattr(agent, "is_connected", False)
            ]

            if connected_agents:
                client = connected_agents[0]
            else:
                try:
                    await client.start()
                except Exception as e:
                    return {
                        "success": False,
                        "error": "هیچ کلاینت فعالی برای دریافت اطلاعات یافت نشد.",
                        "details": str(e)
                    }

        chat = await client.get_chat(clean_link)

        verification = getattr(chat, "verification_status", None)
        if verification:
            is_verified = getattr(verification, "is_verified", False)
            is_scam = getattr(verification, "is_scam", False)
            is_fake = getattr(verification, "is_fake", False)
        else:
            is_verified = getattr(chat, "is_verified", False)
            is_scam = getattr(chat, "is_scam", False)
            is_fake = getattr(chat, "is_fake", False)

        photo_data = None
        if chat.photo:
            photo_data = {
                "small_file_id": chat.photo.small_file_id,
                "big_file_id": chat.photo.big_file_id,
                "big_photo_unique_id": chat.photo.big_photo_unique_id
            }

        data = {
            "id": chat.id,
            "type": chat.type.name if chat.type else None,
            "title": chat.title,
            "username": chat.username,
            "first_name": chat.first_name,
            "last_name": chat.last_name,
            "description": chat.description,
            "members_count": getattr(chat, "members_count", None),
            "is_verified": is_verified,
            "is_scam": is_scam,
            "is_fake": is_fake,
            "photo": photo_data
        }

        clean_data = {k: v for k, v in data.items() if v is not None}

        return {
            "success": True,
            "data": clean_data
        }

    except Exception as e:
        return {
            "success": False,
            "error": "عدم دسترسی به اطلاعات یا اشتباه بودن لینک",
            "details": str(e)
        }
