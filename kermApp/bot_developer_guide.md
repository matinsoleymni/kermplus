# KermPlus Bot Developer Guide

## Overview

KermPlus is a **push-notification dispatch system**. You — the **Telegram bot** — are the *control plane*. Owners register through you, and you push events that the server fans out to their Android devices over FCM.

**Flow:** `Bot registers owner → bot sends event → server delivers to devices via FCM → app ACKs → bot polls delivery progress.`

The bot talks to `/api/bot/*`. The Android app talks to `/api/app/*` — you never touch that side.

---

## Two Credentials, Two Auth Layers

There are **two distinct secrets** on the bot side. Don't confuse them.

| Secret | Length | Scope | Sent as | Used for |
|---|---|---|---|---|
| **Bot secret** | configured | Global (one, shared) | `X-Bot-Secret` header **or** Bearer token | Registering new owners only |
| **`api_token`** | 64 chars | Per-owner | `X-Api-Token` header **or** Bearer token | Every per-owner endpoint |

- The **bot secret** is a single shared value (`config services.bot.secret`, set via env). It gates exactly one endpoint: `POST /api/bot/users`. Comparison is constant-time (`hash_equals`).
- Each owner gets a unique **`api_token`** (and an `app_key` for their app build) at registration. You store the `api_token` and present it on every other call. The server resolves the owner from it and auto-scopes all data to that owner.

> The `app_key` (40 chars) returned at registration is **not** used by the bot. Hand it to whoever builds that owner's APK — it gets compiled into their app.

---

## Base URL & Headers

```
Base URL:  https://your-server.com/api/bot/

# Owner registration (one endpoint):
X-Bot-Secret: <shared_bot_secret>

# Everything else:
X-Api-Token:  <owner_api_token>

# Always:
Content-Type: application/json
Accept:       application/json
```

All responses are JSON.

---

## Endpoints

### 1. Register an Owner

**`POST /api/bot/users`** — auth: **bot secret**

Call this when a new Telegram user first uses the bot. Issues that owner's `api_token` + `app_key`.

**Request:**
```json
{
  "telegram_id": 123456789,   // required, integer, unique
  "username":    "kermdev",   // optional, max 255
  "name":        "Kerm Dev"   // optional, max 255
}
```

**Response `201 Created` (`OwnerResource`):**
```json
{
  "id": 1,
  "telegram_id": 123456789,
  "username": "kermdev",
  "name": "Kerm Dev",
  "api_token": "64-char-token...",
  "app_key": "40-char-key...",
  "created_at": "2026-06-30T10:00:00Z"
}
```

| Status | Meaning |
|---|---|
| `201` | Owner created |
| `401` | Bad/missing bot secret |
| `422` | Validation (e.g. `telegram_id` missing or already registered) |

> ⚠️ **`api_token` is returned only here, at creation.** Persist it immediately (keyed by `telegram_id`). There is no "fetch my token" endpoint. Lose it = re-register problem.

---

### 2. List Devices

**`GET /api/bot/devices`** — auth: **`api_token`**

Returns this owner's registered devices, newest `last_seen_at` first, **paginated 50/page**.

```
GET /api/bot/devices?page=2
```

**Response `200` (`DeviceResource` collection):**
```json
{
  "data": [
    {
      "id": 123,
      "device_id": "abc-123",
      "manufacturer": "Samsung",
      "model": "Galaxy S21",
      "android_version": "13",
      "sdk_int": 33,
      "app_version": "1.0.0",
      "locale": "en_US",
      "last_seen_at": "2026-06-30T09:00:00Z",
      "created_at": "2026-06-22T10:00:00Z"
    }
  ],
  "links": { "...": "..." },
  "meta":  { "current_page": 1, "...": "..." }
}
```

Use the `id` field as `device_id` when targeting a single device in an event. (`fcm_token` is never exposed.)

---

### 3. Send an Event

**`POST /api/bot/events`** — auth: **`api_token`**

Pushes an event to **one device** (if `device_id` given) or **broadcasts to all** the owner's devices.

**Request:**
```json
{
  "event":     "alarm_triggered",        // required, string, max 255
  "data":      { "room": "kitchen" },    // optional — string OR object (free-form)
  "device_id": 123                        // optional — omit to broadcast to all
}
```

- `data` can be a plain string (`"سلام و درود"`) or a structured object. Objects are JSON-encoded (`JSON_UNESCAPED_UNICODE`) into the FCM `data` frame; the app receives it as a string and parses.
- `device_id` is the device's server `id` (from the list endpoint), scoped to the owner — a foreign id 404s.

**Response `201 Created` (`DispatchedEventResource`):**
```json
{
  "id": 456,
  "event": "alarm_triggered",
  "payload": { "room": "kitchen" },
  "device_id": 123,
  "targeted_count": 1,
  "success_count": 1,
  "failure_count": 0,
  "acknowledged_count": 0,
  "created_at": "2026-06-30T10:05:00Z"
}
```

| Status | Meaning |
|---|---|
| `201` | Event dispatched (FCM send attempted per device) |
| `401` | Bad `api_token` |
| `404` | `device_id` not found for this owner |
| `422` | Validation (e.g. missing `event`) |

> `success_count` / `failure_count` reflect **FCM acceptance** at send time, not user receipt. `acknowledged_count` (devices that confirmed) starts at 0 and grows — poll endpoint #5 to track it.

---

### 4. List Events

**`GET /api/bot/events`** — auth: **`api_token`**

Owner's dispatched events, newest first, **paginated 20/page**. Same `DispatchedEventResource` shape, with live `acknowledged_count`.

```
GET /api/bot/events?page=1
```

---

### 5. Show One Event (poll ACK progress)

**`GET /api/bot/events/{event}`** — auth: **`api_token`**

Single event with current acknowledgement progress. `404` if it isn't this owner's event.

```json
{
  "id": 456,
  "event": "alarm_triggered",
  "payload": { "room": "kitchen" },
  "device_id": 123,
  "targeted_count": 1,
  "success_count": 1,
  "failure_count": 0,
  "acknowledged_count": 1,
  "created_at": "2026-06-30T10:05:00Z"
}
```

Poll this to know how many devices actually received and processed the event.

---

## Counts: What They Mean

```
targeted_count     = devices the event aimed at
success_count      = FCM accepted the message     (server → FCM)
failure_count      = FCM rejected (bad token, quota, ...)
acknowledged_count = devices that called ACK      (device → server)  ← real receipt
```

`success` means "handed to Google." `acknowledged` means "the phone got it and processed it." For real-world delivery confidence, watch `acknowledged_count`.

---

## Error Shape

```json
{ "message": "Invalid API token." }
```

Validation `422`:
```json
{
  "message": "The event field is required.",
  "errors": { "event": ["The event field is required."] }
}
```

| Status | Action |
|---|---|
| `401` | Wrong secret/token — fatal, fix credentials |
| `404` | Unknown/foreign device or event — don't retry |
| `422` | Fix the request body |
| `5xx` | Retry with backoff |

---

## Recommended Bot Lifecycle

```
User starts bot
   └─ Known telegram_id?
        NO  → POST /bot/users (X-Bot-Secret) → store api_token + app_key
        YES → load stored api_token

Send a notification
   └─ POST /bot/events (X-Api-Token) {event, data, device_id?}

Show user delivery status
   └─ GET /bot/events/{id} → report acknowledged_count / targeted_count

List user's phones
   └─ GET /bot/devices
```

---

## Key Points

1. **Two secrets.** Bot secret → register owners only. `api_token` → everything else, per owner.
2. **`api_token` shown once.** Persist at registration; no recovery endpoint.
3. **`device_id` in events = the server `id`** from `GET /bot/devices`, not the device's own identifier string.
4. **Omit `device_id` to broadcast** to all the owner's devices.
5. **`success_count` ≠ delivered.** It's FCM acceptance. Poll `acknowledged_count` for real receipt.
6. **`data` is free-form** — string or object; UTF-8/Persian safe.
7. **Pagination:** devices 50/page, events 20/page (`?page=N`).
