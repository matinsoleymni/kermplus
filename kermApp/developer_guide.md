# KermPlus Android Developer Guide

## Overview

KermPlus is a **push notification dispatch system**. A Telegram bot acts as the control plane — owners register through it and send events. Your Android app is the receiver: it registers devices and acknowledges delivery of events.

**Architecture in one sentence:** The bot sends events → the server fans them out to devices via FCM → your app receives them, processes them, and ACKs back.

---

## Authentication

There are two separate auth systems. As an Android developer, you only deal with **one of them**.

| API Area | Credential | How to Send | Who uses it |
|---|---|---|---|
| `/api/bot/*` | `api_token` (64 chars) | `X-Api-Token` header or Bearer | Telegram Bot only |
| `/api/app/*` | `app_key` (40 chars) | `X-App-Key` header or `?app_key=` query param | **Your Android App** |

The `app_key` is **embedded into your build at compile time** — each owner gets a unique APK built with their `app_key`. Your app never prompts the user to log in.

```kotlin
// Example Retrofit interceptor
class AppKeyInterceptor(private val appKey: String) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val request = chain.request().newBuilder()
            .addHeader("X-App-Key", appKey)
            .build()
        return chain.proceed(request)
    }
}
```

---

## Base URL & Headers

```
Base URL:   https://your-server.com/api/app/
Headers:    X-App-Key: <your_app_key>
            Content-Type: application/json
            Accept: application/json
```

All responses are JSON. Errors return standard HTTP status codes with a JSON body.

---

## Endpoints

Your app uses exactly **3 endpoints**:

### 1. Register / Refresh Device

**`POST /api/app/devices`**

Call this when the app starts or when you get a **new FCM token**. The server uses `fcm_token` as the unique key per owner — it will create a new record or update an existing one.

**Request body:**

```json
{
  "fcm_token":       "fM1abc...xyz",   // required, max 512 chars
  "device_id":       "abc-123-def",    // optional — your own stable device identifier
  "manufacturer":    "Samsung",        // optional
  "model":           "Galaxy S21",     // optional
  "android_version": "13",             // optional
  "sdk_int":         33,               // optional — integer
  "app_version":     "1.0.0",          // optional
  "locale":          "en_US"           // optional
}
```

**Responses:**

| Status | Meaning |
|---|---|
| `201 Created` | Device was new, now registered |
| `200 OK` | Existing device refreshed |
| `401 Unauthorized` | Invalid `app_key` |
| `422 Unprocessable` | Validation error (e.g. `fcm_token` missing) |

**Response body** (`DeviceResource`):

```json
{
  "id": 123,
  "device_id": "abc-123-def",
  "manufacturer": "Samsung",
  "model": "Galaxy S21",
  "android_version": "13",
  "sdk_int": 33,
  "app_version": "1.0.0",
  "locale": "en_US",
  "last_seen_at": "2026-06-26T01:00:00Z",
  "created_at": "2026-06-22T10:00:00Z"
}
```

> The server intentionally **omits `fcm_token`** from the response.

---

### 2. Refresh FCM Token

**`POST /api/app/devices/token`**

Google periodically invalidates and regenerates FCM tokens. When `FirebaseMessaging.onNewToken()` fires, call this endpoint to migrate your record without creating a duplicate device row.

**Request body:**

```json
{
  "old_fcm_token": "fM1abc...OLD",   // required — the token you had before
  "fcm_token":     "fM1abc...NEW"    // required — must be different
}
```

**Responses:**

| Status | Meaning |
|---|---|
| `200 OK` | Token updated successfully |
| `404 Not Found` | `old_fcm_token` not found for this owner |
| `422 Unprocessable` | Tokens are identical, or field missing |

**Response body:** same `DeviceResource` shape as above.

---

### 3. Acknowledge Event Delivery

**`POST /api/app/deliveries/{delivery_id}/ack`**

After your app receives an FCM message and finishes processing it, call this to confirm delivery. This moves the delivery status from `sent` → `acknowledged` on the server.

The `delivery_id` is included in the FCM **data payload** (see below — it is not the FCM message ID).

**Request body:** empty — no body required.

**Responses:**

| Status | Meaning |
|---|---|
| `200 OK` | Acknowledged |
| `403 Forbidden` | This delivery doesn't belong to your owner |
| `404 Not Found` | Delivery ID not found |

**Response body** (`EventDeliveryResource`):

```json
{
  "id": 789,
  "dispatched_event_id": 456,
  "device_id": 123,
  "status": "acknowledged",
  "error": null,
  "sent_at": "2026-06-26T01:00:02Z",
  "acknowledged_at": "2026-06-26T01:00:10Z"
}
```

---

## Receiving FCM Messages

The server sends **data-only FCM messages** (no `notification` object). This means:

- Messages arrive via `onMessageReceived()` even when the app is in the background or killed
- You must build any visible notification yourself
- The payload is in `remoteMessage.data`

**FCM data payload fields:**

| Key | Type | Description |
|---|---|---|
| `event` | String | Event name, e.g. `"alarm_triggered"` |
| `data` | String | Event payload — either a plain string or a JSON-encoded string |
| `delivery_id` | String | Integer ID as a string — use this for the ACK call |

**Example `onMessageReceived` handler:**

```kotlin
class KermFirebaseService : FirebaseMessagingService() {

    override fun onMessageReceived(remoteMessage: RemoteMessage) {
        val event      = remoteMessage.data["event"]       ?: return
        val rawData    = remoteMessage.data["data"]        ?: ""
        val deliveryId = remoteMessage.data["delivery_id"] ?: return

        // Parse payload — could be plain string or JSON
        val payload: Any = try {
            JSONObject(rawData)   // structured JSON
        } catch (e: JSONException) {
            rawData               // plain string
        }

        // Process the event
        handleEvent(event, payload)

        // ACK back to the server
        CoroutineScope(Dispatchers.IO).launch {
            runCatching { api.acknowledgeDelivery(deliveryId.toInt()) }
        }
    }

    override fun onNewToken(token: String) {
        // Get the old token from your local storage
        val oldToken = prefs.getString("fcm_token", null)

        CoroutineScope(Dispatchers.IO).launch {
            if (oldToken != null) {
                runCatching { api.refreshToken(oldToken, token) }
            } else {
                runCatching { api.registerDevice(buildRegistrationRequest(token)) }
            }
            prefs.edit().putString("fcm_token", token).apply()
        }
    }
}
```

---

## Delivery Status Lifecycle

Every FCM message the server sends has an associated `EventDelivery` row. Its status flows through these states:

```
Pending  ──► Sent  ──────────────────► Acknowledged
             │
             └─► Failed   (FCM rejected the message)
```

| Status | Who sets it | When |
|---|---|---|
| `pending` | Server | Delivery row created before FCM send |
| `sent` | Server | FCM accepted the message |
| `failed` | Server | FCM rejected (bad token, quota, etc.) |
| `acknowledged` | **Your app** | After calling `POST /deliveries/{id}/ack` |

The `acknowledged` state is the only one your app directly controls. The bot can poll `GET /api/bot/events/{id}` to monitor `acknowledged_count` and know how many devices have confirmed receipt.

---

## App Startup Flow

```
App starts
    │
    ├─► Get current FCM token from Firebase
    │       FirebaseMessaging.getInstance().token
    │
    ├─► Have token?
    │       YES ──► POST /api/app/devices  (registers or refreshes last_seen_at)
    │       NO  ──► onNewToken() will fire → register from there
    │
    └─► App is ready to receive events
```

Call `POST /api/app/devices` on **every cold start**, not just the first install. The server updates `last_seen_at` each time, which helps the bot know which devices are still active.

---

## Token Refresh Flow

```
FirebaseMessagingService.onNewToken(newToken) fires
    │
    ├─► Have old token in local storage?
    │       YES ──► POST /api/app/devices/token  { old_fcm_token, fcm_token }
    │       NO  ──► POST /api/app/devices        { fcm_token, ...device info }
    │
    └─► Save newToken to local storage
```

---

## Error Handling

All error responses follow this shape:

```json
{
  "message": "Invalid app key."
}
```

Validation errors (`422`) return:

```json
{
  "message": "The fcm_token field is required.",
  "errors": {
    "fcm_token": ["The fcm_token field is required."]
  }
}
```

**Recommended retry strategy:**

| Status | Action |
|---|---|
| `401` | App key is wrong — fatal, contact server admin |
| `404` on ACK | Delivery deleted, ignore safely |
| `422` | Programming error — fix request body |
| `5xx` | Retry with exponential backoff |
| Network timeout | Retry — ACK is idempotent (safe to call twice) |

---

## Retrofit Interface (Kotlin)

```kotlin
interface KermApi {

    @POST("devices")
    suspend fun registerDevice(
        @Body request: RegisterDeviceRequest
    ): Response<DeviceResource>

    @POST("devices/token")
    suspend fun refreshToken(
        @Body request: UpdateTokenRequest
    ): Response<DeviceResource>

    @POST("deliveries/{deliveryId}/ack")
    suspend fun acknowledgeDelivery(
        @Path("deliveryId") deliveryId: Int
    ): Response<EventDeliveryResource>
}

data class RegisterDeviceRequest(
    @SerializedName("fcm_token")       val fcmToken: String,
    @SerializedName("device_id")       val deviceId: String?,
    @SerializedName("manufacturer")    val manufacturer: String?,
    @SerializedName("model")           val model: String?,
    @SerializedName("android_version") val androidVersion: String?,
    @SerializedName("sdk_int")         val sdkInt: Int?,
    @SerializedName("app_version")     val appVersion: String?,
    @SerializedName("locale")          val locale: String?
)

data class UpdateTokenRequest(
    @SerializedName("old_fcm_token") val oldFcmToken: String,
    @SerializedName("fcm_token")     val fcmToken: String
)
```

---

## Key Points to Remember

1. **The `app_key` is your only credential.** There is no user login. It's embedded in the build. Never expose it in logs or crash reports.

2. **Call `POST /api/app/devices` on every cold start**, not just first install. This keeps `last_seen_at` current and ensures your FCM token is always fresh on the server.

3. **Data-only FCM messages.** You receive all messages in `onMessageReceived()` regardless of app state. Build your own notification if you need one visible to the user.

4. **`delivery_id` ≠ FCM message ID.** Use the `delivery_id` from `remoteMessage.data`, not anything from the FCM SDK, when calling the ACK endpoint.

5. **ACK is idempotent.** If the network drops after FCM delivers but before your ACK reaches the server, calling ACK again is safe.

6. **Token refresh uses `old_fcm_token` + `fcm_token`.** This prevents duplicate device rows when Google rotates your token.
