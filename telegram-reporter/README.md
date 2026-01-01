# Telegram Reporter

Telegram Reporter is a Telegram automation toolkit that can operate both as a
bot (interactive via Telegram) and as a standalone HTTP API. It manages a fleet
of user sessions, lets you report accounts or messages, reacts to posts at
scale, and keeps a local cache of channels with allowed reactions.

## Requirements
- Python 3.12+
- SQLite (bundled with Python)
- Telegram credentials in `config.py`
- (Optional) Virtual environment, e.g. the provided `env/` directory
- (Recommended) `TgCrypto` for faster encryption when using Pyrogram: `python -m pip install TgCrypto`

## Installation
```bash
python -m pip install -e .
```
The editable install registers the package and pulls all required dependencies:
Pyrogram (via kurigram), FastAPI, Tortoise ORM, APScheduler, and supporting
libraries.

## Configuration
All runtime configuration lives in `config.py`. Update the following values:
- `API_ID`, `API_HASH`: Telegram API credentials.
- `BOT_TOKEN`: bot token for the controller bot.
- `ALLOWED_IDS`: Telegram user IDs allowed to interact with the bot/API.
- `BOT_PROXY`: optional proxy dict for the controller bot, or `None` to disable.
- `AGENT_PROXIES`: optional list of proxy dicts used to rotate connections for user sessions.
- `MAX_FILE_SIZE`: Max upload size for session database imports.
- `TMP_DIR`: Directory used for temporary uploads (created automatically).

Sessions are persisted in `sessions.db`, a SQLite database handled by Tortoise
ORM during startup.

## Running the Telegram Bot
```bash
python app.py
```
The bot launches, connects to Telegram, starts the scheduler that refreshes user
sessions, and idles until you stop the process. Use the configured Telegram bot
to upload session databases, run reports, or queue reaction jobs.

## Running the HTTP API
```bash
uvicorn api_server:api --host 0.0.0.0 --port 8000
```
FastAPI reuses the same `App` lifecycle:
- On startup it boots the Telegram bot, database, scheduler, and session agents.
- On shutdown it gracefully stops everything and closes database connections.

### Available Endpoints
All endpoints accept/return JSON unless noted otherwise.

| Method & Path            | Purpose                                                |
|--------------------------|--------------------------------------------------------|
| `GET /`                  | Health check message.                                  |
| `POST /sessions/upload`  | Multipart upload of a SQLite dump (`accounts` table).  |
| `POST /channels`         | Cache channel reactions from links/usernames.          |
| `DELETE /channels`       | Remove channels from the cache/database.               |
| `POST /reports/account`  | Report a user/bot with a reason and optional comment.  |
| `POST /reports/message`  | Report one or more messages in a channel/group.        |
| `POST /reactions`        | Send reactions to a specific post.                     |
| `GET /status`            | Retrieve active session counts and cached channels.    |

### Example Requests
Add channels:
```bash
curl -X POST http://localhost:8000/channels \
  -H "Content-Type: application/json" \
  -d '{"links":["https://t.me/example_channel"]}'
```

Upload session database:
```bash
curl -X POST http://localhost:8000/sessions/upload \
  -F "file=@/path/to/sessions.db"
```

Report messages:
```bash
curl -X POST http://localhost:8000/reports/message \
  -H "Content-Type: application/json" \
  -d '{"username":"@example","messages":["https://t.me/example/123"],"option":"8","comment":"Spam"}'
```

### Authentication & Security
The sample project does not include HTTP authentication. If you plan to deploy
the API publicly, add authentication (API keys, JWTs, proxies, etc.) and restrict
access the same way the Telegram bot is limited via `ALLOWED_IDS`.

## Development Notes
- Services used by the bot were extracted into the `services/` package so both
  the Telegram handlers and the API reuse identical logic.
- The scheduler runs `AgentManager.launcher` every 30 seconds to refresh active
  sessions. Ensure rotating proxies or credentials are valid to avoid FloodWait.
- Temporary files written during session imports are cleaned up automatically.

## License
Set an appropriate license for your competition or deployment needs.
