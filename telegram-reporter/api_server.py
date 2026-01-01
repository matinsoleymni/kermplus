from __future__ import annotations

import logging
import os
from contextlib import asynccontextmanager
from typing import Annotated

from fastapi import Body, Depends, FastAPI, File, HTTPException, UploadFile, status
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field, field_validator

from app import App
from services import (
    add_channels,
    get_status,
    import_sessions,
    remove_channels,
    report_account,
    report_message,
    send_reactions,
)

logger = logging.getLogger(__name__)

app_state = App()


class ChannelPayload(BaseModel):
    links: list[str] = Field(..., min_length=1)

    @field_validator("links")
    @classmethod
    def clean_links(cls, value: list[str]) -> list[str]:
        cleaned = [link.strip() for link in value if link and link.strip()]
        if not cleaned:
            raise ValueError("links cannot be empty.")
        return cleaned


class ReportAccountPayload(BaseModel):
    username: str
    reason: str
    comment: str = Field(default="")


class ReportMessagePayload(BaseModel):
    username: str
    messages: list[str | int] = Field(..., min_length=1)
    option: str
    comment: str = Field(default="")


class ReactionPayload(BaseModel):
    link: str
    emoji: str | None = None


@asynccontextmanager
async def lifespan(_api: FastAPI):
    await app_state.initialize(start_scheduler=True, raise_on_error=False)

    if app_state.initialization_error:
        logger.warning(
            "Telegram client initialization failed during startup: %s",
            app_state.initialization_error,
        )
    try:
        yield
    finally:
        await app_state.stop()


api = FastAPI(title="Telegram Reporter API", version="0.1.0", lifespan=lifespan)


async def get_app() -> App:
    if not app_state._initialized:  # pylint: disable=protected-access
        await app_state.initialize(start_scheduler=True, raise_on_error=False)

    if app_state.initialization_error:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail={
                "message": "Telegram client failed to initialize. Check network/proxy configuration.",
                "error": f"{app_state.initialization_error.__class__.__name__}: {app_state.initialization_error}",
            },
        )
    return app_state


@api.get("/")
async def root():
    return {"message": "Telegram Reporter API is running."}


@api.post("/sessions/upload")
async def upload_sessions(
    file: UploadFile = File(...),
    app: Annotated[App, Depends(get_app)] = None,
):
    data = await file.read()
    result = await import_sessions(app, file.filename, data)
    if not result.get("added") and result.get("detail"):
        raise HTTPException(status_code=400, detail=result)
    return JSONResponse(result)


@api.post("/channels")
async def add_channels_route(
    payload: ChannelPayload,
    app: Annotated[App, Depends(get_app)] = None,
):
    result = await add_channels(app, payload.links)
    if not (result["added"] or result["updated"]) and result["errors"]:
        raise HTTPException(status_code=400, detail=result)
    return JSONResponse(result)


@api.delete("/channels")
async def remove_channels_route(
    payload: ChannelPayload = Body(...),
    app: Annotated[App, Depends(get_app)] = None,
):
    result = await remove_channels(app, payload.links)
    return JSONResponse(result)


@api.post("/reports/account")
async def report_account_route(
    payload: ReportAccountPayload,
    app: Annotated[App, Depends(get_app)] = None,
):
    result = await report_account(app, payload.username, payload.reason, payload.comment)
    if not result["reported"] and result["errors"]:
        raise HTTPException(status_code=400, detail=result)
    return JSONResponse(result)


@api.post("/reports/message")
async def report_message_route(
    payload: ReportMessagePayload,
    app: Annotated[App, Depends(get_app)] = None,
):
    result = await report_message(
        app,
        payload.username,
        payload.messages,
        payload.option,
        payload.comment,
    )
    if not result["reported"] and result["errors"]:
        raise HTTPException(status_code=400, detail=result)
    return JSONResponse(result)


@api.post("/reactions")
async def send_reaction_route(
    payload: ReactionPayload,
    app: Annotated[App, Depends(get_app)] = None,
):
    result = await send_reactions(app, payload.link, payload.emoji)
    if not result["sent"] and result["errors"]:
        raise HTTPException(status_code=400, detail=result)
    return JSONResponse(result)


@api.get("/status")
async def status_route(
    app: Annotated[App, Depends(get_app)] = None,
):
    result = await get_status(app)
    return JSONResponse(result)


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(
        "api_server:api",
        host=os.getenv("API_HOST", "0.0.0.0"),
        port=int(os.getenv("API_PORT", "8083")),
    )
