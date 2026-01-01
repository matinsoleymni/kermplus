import asyncio
import random
import config

from lib.api import Client, handlers, functions, errors, types
from typing import TYPE_CHECKING, Callable
from functools import wraps

if TYPE_CHECKING:
    import app

def humanize(func: Callable):
    @wraps(func)
    async def wrapper(client: "Agent", *args, **kwargs):
        
        try:
            
            if client.stopped:
                return None
            
            if client.flood.is_set():
                await client.flood.wait()
            
            client.active_process += 1
            
            await asyncio.sleep(random.randint(10, 20) / 10)
        
            return await func(
                client,
                *args,
                **kwargs
            )
        
        except errors.FloodWait as error:
            client.flood.set(error.value)
            asyncio.sleep(error.value)
            client.flood.reset()
        
        except (
            errors.UserDeactivated,
            errors.UserDeactivatedBan,
            errors.SessionExpired,
            errors.SessionRevoked
        ):
            await client.parent.database.Session.filter(pk=client.session_id).delete()
        
        except asyncio.TimeoutError:
            client.stopped = True
            del client.parent.agent.sessions[client.session_id]
            
            try:
                if client.is_connected:
                    await client.stop()
                
                del client
            
            except Exception as error:
                print(error)
            
        finally:
            client.flood.clear()
            client.active_process -= 1
            
    return wrapper

class Reporter(Client):
    
    def __init__(
        self,
        app: "app.App",
        api_id: int,
        api_hash: str,
        bot_token: str,
        loop: asyncio.AbstractEventLoop,
        handlers: list[handlers.Handler] = []
    ):
        
        self.parent = app
        
        super().__init__(
            name=self.__class__.__name__,
            api_id=api_id,
            api_hash=api_hash,
            bot_token=bot_token,
            loop=loop,
            link_preview_options=types.LinkPreviewOptions(
                is_disabled=True
            ),
            proxy=config.BOT_PROXY
        )
        
        for handler in handlers:
            self.add_handler(handler, handler.group)

class Flood(asyncio.Event):
    
    def __init__(self):
        super().__init__()
        self.time = 0
    
    def set(self, time: int = 0):
        self.time = time
        return super().set()
    
    def reset(self):
        self.time = 0
        return self.clear()

class Agent(Client):
    
    def __init__(
        self,
        app: "app.App",
        name: str,
        session: str,
        proxy: dict,
        session_id: int,
    ):
        
        self.parent = app
        
        super().__init__(
            name=name,
            session_string=session,
            proxy=proxy,
            no_updates=True,
            skip_updates=True
        )
        
        self.flood = Flood()
        self.active_process = 0
        self.session_id = session_id
        self.stopped = False
        
    async def start(self) -> bool:
        is_authorized = await self.connect()
        
        if is_authorized:
            await self.initialize()
        
        await self.invoke(functions.GetState(), retries=3, timeout=5)
        return is_authorized
    
    @humanize
    async def invoke(
        self, 
        query, 
        retries: int = 3, 
        timeout: int | None = None, 
        sleep_threshold = None, 
        retry_delay: int | None = None, 
        recaptcha_token = None, 
        business_connection_id = None,
        *args,
        **kwargs
    ):
        return await super().invoke(
            query, 
            retries or self.session.MAX_RETRIES, 
            timeout or self.session.WAIT_TIMEOUT, 
            sleep_threshold, 
            retry_delay or self.session.RETRY_DELAY, 
            recaptcha_token, 
            business_connection_id,
            *args,
            **kwargs
        )
