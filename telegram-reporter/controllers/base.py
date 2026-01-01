from typing import Coroutine, TYPE_CHECKING
from functools import wraps

if TYPE_CHECKING:
    import core.client

def lock(coroutine: Coroutine):
    @wraps(coroutine)
    async def wrapper(client: "core.client.Reporter", update, *args, **kwargs):
        
        if client.parent.lock.is_set():
            await client.send_message(
                update.from_user.id,
                "✅ Your request has been added to the queue."
            )
            await client.parent.lock.wait()
        
        client.parent.lock.set()
        
        try:
            return await coroutine(client, update, *args, **kwargs)
        
        except Exception as error:
            print(
                f"[{error.__class__.__name__}] {error}"
            )
        
        finally:
            client.parent.lock.clear()
        
    return wrapper