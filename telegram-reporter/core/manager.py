import asyncio
import copy
import config

from .client import Agent
from lib.api import errors
from typing import TYPE_CHECKING, Iterator
from itertools import cycle

if TYPE_CHECKING:
    import app

class AgentManager:
    
    def __init__(
        self,
        app: "app.App"
    ):
        self.app = app
        self.sessions: dict[int, Agent] = {}
        self.lock = asyncio.Event()
        self._proxy_cycle = cycle(config.AGENT_PROXIES or [None])
    
    def _get_proxy(self):
        proxy = next(self._proxy_cycle)
        return copy.deepcopy(proxy) if isinstance(proxy, dict) else None
    
    async def _launch(
        self,
        agent: Agent
    ):
        
        try:
            await agent.start()
        
        except (
            errors.FrozenMethodInvalid, errors.SessionRevoked, errors.UserDeactivated, 
            errors.UserDeactivatedBan, errors.SessionExpired
        ):
            await self.app.database.Session.filter(pk=agent.session_id).delete()
        
        except asyncio.TimeoutError:
            try:
                await agent.stop()
            
            except:
                ...
        
        except errors.RPCError as error:
            print(f"[Start Client - {error.__class__.__name__}] {error}")
        
        else:
            if agent.is_connected:
            
                self.sessions.update(
                    {
                        agent.session_id: agent
                    }
                )
                
                print(f"[{self.sessions.__len__()}] {agent.session_id} - {agent.name} activated.")
        
    
    async def launcher(self):
        
        if self.lock.is_set():
            await self.lock.wait()
        
        self.lock.set()
                
        try:
            
            tasks = [
                asyncio.create_task(
                    self._launch(
                        Agent(
                            app=self.app,
                            name=session.number,
                            session=session.string,
                            proxy=self._get_proxy(),
                            session_id=session.id
                        )
                    )
                ) for session in await self.app.database.Session.filter().exclude(pk__in=[id for id in self.sessions.keys()])
            ]
            
            if not tasks:
                return None
            
            await asyncio.gather(
                *tasks, 
            )
            
        finally:
            if self.lock.is_set():
                self.lock.clear()
    
    def get_non_flooded_sessions(self):
        return [cli for cli in self.sessions.values() if not cli.flood.is_set()]
    
    def get_sessions_ordered_by_flood_time(self):
        return sorted([cli for cli in self.sessions.values() if cli.flood.is_set()], key=lambda x:x.flood.time)
    
    async def stop(self):
        for _, session in self.sessions.items():
            try:
                await session.stop()
            
            except ConnectionError:
                continue
            
            else:
                print(f"[{session.session_id}] {session.name} stopped.")
    
    async def statistics(self):
        return (
            (total := self.sessions.__len__()),
            await self.app.database.Session.all().count(),
            (flooded := tuple(1 for session in self.sessions.values() if session.flood.is_set()).__len__()),
            total - flooded,
            sum(cli.active_process for cli in self.sessions.values())
        )
    
    def __iter__(self) -> Iterator[Agent]:
        for _, session in self.sessions.items():
            yield session
