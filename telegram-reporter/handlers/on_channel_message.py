import json
import random
import asyncio

from core.client import Reporter
from lib.api import handlers, types, filters, errors
from itertools import cycle

class MessageChannelHandler(handlers.MessageHandler):
    __slots__ = []
    
    def __init__(self):
        self.group = 0
        super().__init__(self.OnUpdate, filters.channel)
    
    async def OnUpdate(self, client: Reporter, update: types.Message):

        if update.chat.id not in client.parent.allowed_channels:
            return None
        
        non_flooded = client.parent.agent.get_non_flooded_sessions()
        flooded = [session for session in client.parent.agent.get_sessions_ordered_by_flood_time() if (session.flood.time // 60) < 3]
        sessions = [*non_flooded, *flooded]
        channel = await client.parent.database.Channel.get_or_none(channel_id=update.chat.id)
        
        if not channel:
            return None
        
        reactions = channel.reactions
        delay = cycle([i for i in range(1, 5)])
        counter = 0
        
        for session in sessions:
            
            if not session.is_connected:
                continue
            
            try:
                await session.send_reaction(
                    update.chat.username,
                    update.id,
                    random.choice(reactions)
                )
                
                counter += 1
            
            except errors.UsernameNotOccupied:
                continue
        
        if counter != 0 and counter % 5 == 0:
            await asyncio.sleep(delay.__next__())
            
            
            