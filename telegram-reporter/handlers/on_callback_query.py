from core.client import Reporter
from lib.api import handlers, types, filters

class CallbackQueryHandler(handlers.CallbackQueryHandler):
    __slots__ = []
    
    def __init__(self):
        self.group = 0
        super().__init__(self.OnUpdate)
    
    async def OnUpdate(self, client: Reporter, update: types.CallbackQuery):
        
        if await client.parent.input.answer(client, update.from_user, update):
            return None
        
        