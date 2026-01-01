import asyncio
import config

from lib.api import idle
from database import Database

from core.client import Reporter
from core.async_input import AsyncInput
from core.manager import AgentManager
from handlers import MessageHandler, CallbackQueryHandler, MessageChannelHandler

from apscheduler.schedulers.asyncio import AsyncIOScheduler
from apscheduler.triggers.interval import IntervalTrigger

class App:
    
    def __init__(self):
        
        self.scheduler = AsyncIOScheduler()
        self.loop = asyncio.get_event_loop()
        
        self.bot = Reporter(
            self,
            api_id=config.API_ID,
            api_hash=config.API_HASH,
            bot_token=config.BOT_TOKEN,
            loop=self.loop,
            handlers=[MessageHandler(), CallbackQueryHandler(), MessageChannelHandler()]
        )
        
        self.database = Database()
        self.input = AsyncInput()
        self.lock = asyncio.Event()
        self.agent = AgentManager(self)
        self.lock = asyncio.Event()
        self.allowed_channels = set()
        self.initialization_error = None
        self._initialized = False
        self._scheduler_started = False
        
        config.TMP_DIR.mkdir(parents=True, exist_ok=True)
        
        self.scheduler.add_job(
            self.agent.launcher,
            trigger=IntervalTrigger(seconds=30),
            name="launch_agents"
        )
        
    async def update_allowed_channel(self):
        for channel in await self.database.Channel.all():
            self.allowed_channels.add(channel.channel_id)
    
    async def initialize(self, start_scheduler: bool = True, raise_on_error: bool = True):
        if self._initialized:
            if start_scheduler and not self._scheduler_started:
                self.scheduler.start()
                self._scheduler_started = True
            return
        
        self.initialization_error = None
        bot_started = False
        db_connected = False
        
        try:
            await self.bot.start()
            bot_started = True
            
            await self.database.connect()
            db_connected = True
            
            await self.update_allowed_channel()
            await self.agent.launcher()
        
        except Exception as error:
            self.initialization_error = error
            
            if bot_started and self.bot.is_connected:
                await self.bot.stop()
            
            if db_connected:
                await self.database.disconnect()
            
            if raise_on_error:
                raise RuntimeError(
                    "Failed to initialize Telegram Reporter. "
                    "Check your network connectivity and proxy credentials. "
                    f"Original error: {error.__class__.__name__}: {error}"
                ) from error
            
            return
        
        if start_scheduler and not self._scheduler_started:
            self.scheduler.start()
            self._scheduler_started = True
        
        self._initialized = True
    
    async def start(self): 
        
        await self.initialize(start_scheduler=True)
        await idle()
        await self.stop()
    
    async def stop(self):
        
        if not self._initialized and not self._scheduler_started:
            return
        
        if self._scheduler_started:
            self.scheduler.shutdown(wait=False)
            self._scheduler_started = False
        
        await self.agent.stop()
        
        if self.bot.is_connected:
            await self.bot.stop()
        
        await self.database.disconnect()
        self.input.cancel()
        self.allowed_channels.clear()
        self._initialized = False
    
    def run(self):
        self.loop.run_until_complete(self.start())
        
if __name__ == "__main__":
    App().run()
        
        
        
        
        
        
