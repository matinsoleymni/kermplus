import config
from core.client import Reporter
from lib.api import handlers, types, filters
from controllers import UploadSession, Status, ReportMessage, ReportAccount, Reaction, AddCahnnel, RemoveCahnnel

class MessageHandler(handlers.MessageHandler):
    __slots__ = []
    
    def __init__(self):
        self.group = 0
        super().__init__(self.OnUpdate, filters.private & filters.user(config.ALLOWED_IDS))
    
    async def OnUpdate(self, client: Reporter, update: types.Message):

        if await client.parent.input.answer(client, update.from_user, update):
            return None
        
        match update.text and update.text.removeprefix("/").strip().split()[0]:
            
            case "start":
                await client.send_message(update.from_user.id, "✅ Bot is up.")
                
            case "stop":
                await client.send_message(update.from_user.id, "👍 Accpted!")
                client.loop.create_task(
                    client.parent.stop()
                )
            
            case "session":
                return await UploadSession(client=client, update=update)
                
            case "account":
                return await ReportAccount(client=client, update=update)
                
            case "message":
                return await ReportMessage(client=client, update=update)
            
            case "reaction":
                return await Reaction(client=client, update=update)
            
            case "add":
                return await AddCahnnel(client=client, update=update)
            
            case "remove":
                return await RemoveCahnnel(client=client, update=update)
                
            case "activate":
                await client.send_message(update.from_user.id, "👍 Accpted!")
                await client.parent.agent.launch()
                
            case "deactivate":
                await client.send_message(update.from_user.id, "👍 Accpted!")
                await client.parent.agent.stop()
                
            case "stat":
                return await Status(client=client, update=update)
                
            
            
        
        
        
        