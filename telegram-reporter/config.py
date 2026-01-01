import json
import os
from pathlib import Path
from typing import Any


def _load_json_list(value: str, default: list[Any]) -> list[Any]:
    if not value:
        return default
    try:
        parsed = json.loads(value)
        if isinstance(parsed, list):
            return parsed
    except json.JSONDecodeError:
        pass
    return default


BASE_DIR = Path(__file__).parent

SESSION_DB = Path(os.getenv("TELEGRAM_SESSION_DB", BASE_DIR / "sessions.db"))
TMP_DIR = Path(os.getenv("TELEGRAM_TMP_DIR", BASE_DIR / "TMP"))
MAX_FILE_SIZE = int(os.getenv("TELEGRAM_MAX_FILE_SIZE", 5 * 1024 * 1024))

API_ID = int(os.getenv("TELEGRAM_API_ID", 2040))
API_HASH = os.getenv("TELEGRAM_API_HASH", "b18441a1ff607e10a989891a5462e627")

BOT_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN", "8249697674:AAE9BF09Vy9jhe3q3UkQlCFLaqnldekLw_w")

ALLOWED_IDS = [
    int(x.strip())
    for x in os.getenv("TELEGRAM_ALLOWED_IDS", "8200290641,772127505").split(",")
    if x.strip()
]

BOT_PROXY_ENV = os.getenv("TELEGRAM_BOT_PROXY")
BOT_PROXY = None
if BOT_PROXY_ENV:
    try:
        BOT_PROXY = json.loads(BOT_PROXY_ENV)
    except json.JSONDecodeError:
        BOT_PROXY = None

AGENT_PROXIES = _load_json_list(os.getenv("TELEGRAM_AGENT_PROXIES", ""), [])  # Example: [{"scheme": "http", "hostname": "...", "port": ...}]

OPTIONS = [
    {
        "text": "I don’t like it",
        "option": '1'
    },
    {
        "text": "Child abuse",
        "option": '2'
    },
    {
        "text": "Violence",
        "option": '3'
    },
    {
        "text": "Illegal goods",
        "option": '4',
        "other": [
            {
                "text": "Weapons",
                "option": '41'
            },
            {
                "text": "Drugs",
                "option": '42'
            },
            {
                "text": "Fake documents",
                "option": '43'
            },
            {
                "text": "Counterfeit money",
                "option": '44'
            },
            {
                "text": "Other goods",
                "option": '45'
            }
        ]
    },
    {
        "text": "Illegal adult content",
        "option": '5',
        "other": [
            {
                "text": "Child abuse",
                "option": "b'51'"
            },
            {
                "text": "sexual imagery",
                "option": "b'52'"
            },
            {
                "text": "Other illegal sexual content",
                "option": "b'53'"
            },
        ]
    },
    {
        "text": "Personal data",
        "option": '6',
        "other": [
            {
                "text": "Private images",
                "option": '61'
            },
            {
                "text": "Phone number",
                "option": '62'
            },
            {
                "text": "Address",
                "option": '63'
            },
            {
                "text": "Other personal information",
                "option": '64'
            },
        ]
    },
    {
        "text": "Terrorism",
        "option": '7'
    },
    {
        "text": "Scam or spam",
        "option": '8',
        "other": [
            {
                "text": "Phishing",
                "option": '81'
            },
            {
                "text": "Impersonation",
                "option": '82'
            },
            {
                "text": "Fraudulent sales",
                "option": '83'
            },
            {
                "text": "Spam",
                "option": '84'
            }
        ]
    },
    {
        "text": "Copyright",
        "option": '9'
    },
    {
        "text": "Other",
        "option": 'a'
    },
    {
        "text": "It’s not illegal, but must be taken down",
        "option": 'b'
    }
]
