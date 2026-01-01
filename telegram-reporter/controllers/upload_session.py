import zlib
import config
import os
import utils

from tortoise.exceptions import IntegrityError
from lib.api import types, filters
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    import core.client

async def UploadSession(client: "core.client.Reporter", update: types.Message):

    message: types.Message = await client.parent.input.ask(
        "🗃 Send database file with .db or .sqlite extension?",
        client, update.from_user, update.chat, filters.document
    )
    
    document = message.document
    
    if not document:
        return None
    
    if document.file_size > config.MAX_FILE_SIZE:
        return await client.send_message(update.from_user.id, "⚠️ The uploaded file exceeds the specified size.")
    
    _, ext = os.path.splitext(document.file_name)
    
    file_name = "session_" + zlib.crc32(
        document.file_id.__str__().encode()
    ).__str__() + (ext or ".db")
    
    file_path = await client.download_media(document.file_id, config.TMP_DIR / file_name)
    
    if not file_path:
        return await client.send_message(update.from_user.id, "❌ Failed to download file.")
    
    message = await client.send_message(update.from_user.id, "⏳ Adding sessions ...")
    counter = 0
    
    for session_string, userid, number, password in await utils.extract_accounts(file_path):
        
        try:
            await client.parent.database.Session.create(
                userid=userid,
                string=session_string,
                number=number,
                password=password
            )
            counter += 1
            
        except IntegrityError:
            pass
    
    await message.edit_text(f"✅ The process was completed successfully and {counter} sessions were added to the database.")
    os.remove(file_path)