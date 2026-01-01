import aiosqlite

async def extract_accounts(file_path: str) -> tuple[str, str, str, str]:
    async with aiosqlite.connect(file_path) as db:
        cursor = await db.execute("SELECT session_string, user_id, number, password FROM accounts;")
        return await cursor.fetchall()

def normalize_username(username: str):
    return (
        "@" + username.replace(" ", "").removeprefix("@").removeprefix("http://").removeprefix("https://").removeprefix("t.me").removeprefix("/").removesuffix("/")
        if username else None
    )

def normalize_post_link(link: str) -> list[str, int]:
    link = link.removeprefix("http://").removeprefix("https://").removeprefix("t.me").removeprefix("/")
    username, message_id = link.split("/")
    return username, int(message_id)