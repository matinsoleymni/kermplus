import config

from tortoise import Tortoise
from .session import Session
from .channel import Channel

class Database:
    
    Session = Session
    Channel = Channel
    
    async def connect(self):
        await Tortoise.init(
            db_url="sqlite:///" + config.SESSION_DB.__str__(),
            modules={
                'models': [
                    'database.session',
                    'database.channel',
                ]
            }
        )
        
        await Tortoise.generate_schemas()
    
    async def disconnect(self):
        await Tortoise.close_connections()
    
    