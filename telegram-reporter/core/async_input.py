import asyncio
from typing import TYPE_CHECKING
from lib.api import types, filters

if TYPE_CHECKING:
    import core.client

class AsyncInput:
    
    def __init__(self):
        self.queue = {}
    
    async def ask(self, text: str, client: "core.client.Reporter", from_user: types.User, chat: types.Chat, filter: filters.Filter, reply_markup: types.InlineKeyboardMarkup = None):
        
        fu = asyncio.Future()
        
        self.queue.update(
            {
                from_user.id: (fu, filter),
            }
        )
        
        asked = await client.send_message(
            chat.id,
            text=text,
            reply_markup=reply_markup,
            link_preview_options=types.LinkPreviewOptions(
                is_disabled=True
            )
        )
        response = None
        
        try:
            response = await asyncio.wait_for(fu, 60 * 2)
            setattr(response, "asked", asked)
        
        except asyncio.TimeoutError:
            await asked.delete()
        
        finally:
            try:
                del self.queue[from_user.id]
            
            except KeyError:
                ...
        
        return response
    
    async def answer(self, client: "core.client.Reporter", from_user: types.User, update: types.Update):
        
        if from_user.id in self.queue:
            fu, flt = self.queue[from_user.id]
            
            if flt and not await flt(client, update):
                return False
            
            if fu.done():
                del self.queue[from_user.id]
            
            else:
                fu.set_result(update)
                return True
    
    def cancel(self):
        for fu, _ in self.queue.values():
            fu.cancel()