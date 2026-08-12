# apk-service

سرویس ساده Go برای تزریق توکن کاربر داخل یک APK پایه، zipalign و **امضا با
کلید مجزای هر کاربر**.

## منطق کلیدها

- هر کاربر (`user_id`) یک keystore جداگانهٔ خودش را دارد: `KEYSTORE_DIR/<user_id>.jks`
- بار اول که برای یک کاربر درخواست می‌آید، سرویس با `keytool` یک جفت‌کلید RSA
  ۲۰۴۸ بیتی تازه می‌سازد.
- درخواست‌های بعدی برای همان کاربر، از همان keystore موجود دوباره استفاده
  می‌کنند (یعنی APKهای بعدی همان کاربر همیشه با همان کلید امضا می‌شوند —
  این برای این‌که Android به‌روزرسانی اپ را قبول کند لازم است. اگر هر بار
  کلید عوض شود، نصب آپدیت روی گوشی کاربر شکست می‌خورد).
- اگر `user_id` ارسال نشود، خودِ `token` به‌عنوان شناسهٔ کلید استفاده می‌شود.
- تمام keystoreها یک storepass/keypass مشترک دارند (از `KEYSTORE_PASS`)؛ چیزی
  که واقعاً بین کاربران فرق می‌کند خودِ جفت‌کلید (keypair) است.
- ساخت کلید هر کاربر با قفل مخصوص همان کاربر (in-memory mutex) و
  double-checked locking محافظت می‌شود تا دو درخواست هم‌زمان دو بار کلید
  نسازند یا فایل نیمه‌کاره تولید نشود (نوشتن در فایل `.tmp` و سپس rename اتمیک).

## پیش‌نیازها روی سرور

```bash
sudo apt update
sudo apt install -y default-jdk unzip zip golang-go

mkdir -p ~/android-sdk/cmdline-tools
cd ~/android-sdk/cmdline-tools
wget https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip
unzip commandlinetools-linux-*.zip
mv cmdline-tools latest
cd latest/bin
yes | ./sdkmanager --sdk_root=$HOME/android-sdk "build-tools;34.0.0"

export PATH=$PATH:$HOME/android-sdk/build-tools/34.0.0
```

## ساخت سرویس

```bash
cd apk-service
go build -o apk-service main.go
```

## اجرا

```bash
export BASE_APK_PATH=./base.apk   # کنار خودِ سرویس؛ همیشه از همین خوانده می‌شود
export OUTPUT_DIR=./output
export ASSET_PATH=assets/token.txt
export KEYSTORE_DIR=/etc/apk-service/keystores
export KEYSTORE_PASS='a-strong-shared-password'
export KEY_VALIDITY_DAYS=10000
export KEY_DNAME_OU=App
export KEY_DNAME_O="Your Company"
export KEY_DNAME_L=Tehran
export KEY_DNAME_S=Tehran
export KEY_DNAME_C=IR
export LISTEN_ADDR=:8080

# آدرس عمومی سرویس برای ساخت لینک دانلود (اگر خالی باشد از Host درخواست استفاده می‌شود)
export PUBLIC_BASE_URL='https://apk.example.com'
# سرویس دیگری که نتیجهٔ اسکن با یک POST به آن اطلاع داده می‌شود
export CALLBACK_URL='https://your-backend.example.com/apk/scan-callback'

# اسکن VirusTotal — اگر VT_API_KEY خالی باشد اسکن غیرفعال است
export VT_API_KEY='your-virustotal-api-key'
export VT_POLL_TIMEOUT=5m     # حداکثر انتظار برای آماده‌شدن نتیجه
export VT_POLL_INTERVAL=15s   # فاصلهٔ چک‌کردن وضعیت تحلیل

./apk-service
```

## استفاده

`/generate` بلافاصله یک JSON با **لینک دانلود** APK امضاشده برمی‌گرداند؛ خودِ
فایل روی سرور در `OUTPUT_DIR` ذخیره می‌ماند. اگر `VT_API_KEY` تنظیم شده باشد،
اسکن VirusTotal به‌صورت **خودکار و در پس‌زمینه** روی هر APK ساخته‌شده اجرا
می‌شود (چون ممکن است زمان‌بر باشد، درخواست منتظرش نمی‌ماند) و نتیجه پس از
آماده‌شدن با یک `POST` به `CALLBACK_URL` اطلاع داده می‌شود.

```bash
curl -X POST http://localhost:8080/generate \
  -H "Content-Type: application/json" \
  -d '{"user_id":"u123","token":"USER_TOKEN_VALUE"}'
```

یا با GET:

```bash
curl "http://localhost:8080/generate?user_id=u123&token=USER_TOKEN_VALUE"
```

پاسخ فوری:

```json
{
  "user_id": "u123",
  "file": "user_u123_1723276800000000000.apk",
  "download_url": "https://apk.example.com/download/user_u123_1723276800000000000.apk",
  "status": "scanning"
}
```

- `status`: `scanning` اگر اسکن پس‌زمینه شروع شده باشد، یا `no_scan` اگر
  `VT_API_KEY` تنظیم نشده باشد.
- دانلود فایل ساخته‌شده:

```bash
curl -O https://apk.example.com/download/user_u123_1723276800000000000.apk
```

بررسی سلامت سرویس:

```bash
curl http://localhost:8080/health
```

## اطلاع‌رسانی نتیجهٔ اسکن (callback)

اسکن VirusTotal روی هر APK ساخته‌شده **به‌صورت خودکار** انجام می‌شود. چون
ممکن است زمان‌بر باشد، درخواست `/generate` منتظر آن نمی‌ماند؛ فایل روی سرور
ذخیره می‌شود و پس از آماده‌شدن نتیجه، سرویس یک `POST` با بدنهٔ JSON زیر به
`CALLBACK_URL` می‌فرستد تا سرویس دیگر (مثلاً backend اصلی) از وضعیت فایل و
لینک دانلودش مطلع شود:

```json
{
  "user_id": "u123",
  "file": "user_u123_1723276800000000000.apk",
  "download_url": "https://apk.example.com/download/user_u123_1723276800000000000.apk",
  "scan": {
    "analysis_id": "NjY...==",
    "sha256": "e3b0c44298fc1c149afbf4c8996fb924...",
    "status": "completed",
    "verdict": "clean",
    "malicious": 0,
    "suspicious": 0,
    "harmless": 0,
    "undetected": 60,
    "permalink": "https://www.virustotal.com/gui/file/e3b0c442..."
  }
}
```

- اگر اسکن با خطا مواجه شود، به‌جای `scan` فیلد `error` با متن خطا فرستاده می‌شود.
- اگر `CALLBACK_URL` تنظیم نشده باشد، نتیجه فقط در لاگ سرویس ثبت می‌شود.

## اسکن دستی یک فایل دلخواه

علاوه بر اسکن خودکار `/generate`، می‌توانید هر فایلی را به‌صورت هم‌زمان
(synchronous) اسکن کنید و نتیجه را مستقیم بگیرید:

```bash
curl -X POST http://localhost:8080/scan -F "file=@user_u123.apk"
```

### نمونهٔ خروجی

```json
{
  "analysis_id": "NjY...==",
  "sha256": "e3b0c44298fc1c149afbf4c8996fb924...",
  "status": "completed",
  "verdict": "clean",
  "malicious": 0,
  "suspicious": 0,
  "harmless": 0,
  "undetected": 60,
  "permalink": "https://www.virustotal.com/gui/file/e3b0c442..."
}
```

- `verdict`: یکی از `clean` / `suspicious` / `malicious` / `unknown`.
  - `malicious` اگر حداقل یک موتور بدافزار تشخیص دهد.
  - `suspicious` اگر مشکوک علامت بخورد ولی صراحتاً بدافزار نه.
  - `unknown` اگر تحلیل تا پایان `VT_POLL_TIMEOUT` هنوز آماده نشده باشد
    (در این حالت `status` برابر `timeout` است و می‌توانید بعداً با
    `analysis_id` نتیجه را از VirusTotal بگیرید).
- فایل‌های بزرگ‌تر از ۳۲MB به‌صورت خودکار از طریق endpoint مخصوص
  (`/files/upload_url`) آپلود می‌شوند (تا سقف ۶۵۰MB).

> نکته: پلن رایگان VirusTotal محدودیت نرخ دارد (حدود ۴ درخواست در دقیقه)؛
> برای استفادهٔ پرحجم به یک کلید تجاری نیاز دارید.

## اجرا به‌عنوان سرویس systemd

فایل `/etc/systemd/system/apk-service.service`:

```ini
[Unit]
Description=APK token injection service
After=network.target

[Service]
Type=simple
User=apkservice
WorkingDirectory=/opt/apk-service
EnvironmentFile=/etc/apk-service/env
ExecStart=/opt/apk-service/apk-service
Restart=on-failure
RestartSec=3

[Install]
WantedBy=multi-user.target
```

فایل `/etc/apk-service/env` (با `chmod 600` و مالکیت کاربر `apkservice`):

```
BASE_APK_PATH=/opt/apk-service/base.apk
OUTPUT_DIR=/opt/apk-service/output
ASSET_PATH=assets/token.txt
KEYSTORE_DIR=/etc/apk-service/keystores
KEYSTORE_PASS=a-strong-shared-password
KEY_VALIDITY_DAYS=10000
KEY_DNAME_OU=App
KEY_DNAME_O=Your Company
KEY_DNAME_L=Tehran
KEY_DNAME_S=Tehran
KEY_DNAME_C=IR
LISTEN_ADDR=:8080
PUBLIC_BASE_URL=https://apk.example.com
CALLBACK_URL=https://your-backend.example.com/apk/scan-callback
VT_API_KEY=your-virustotal-api-key
VT_POLL_TIMEOUT=5m
VT_POLL_INTERVAL=15s
PATH=/usr/bin:/usr/local/bin:/home/apkservice/android-sdk/build-tools/34.0.0
```

سپس:

```bash
sudo mkdir -p /etc/apk-service/keystores
sudo chown -R apkservice:apkservice /etc/apk-service
sudo chmod 700 /etc/apk-service/keystores

sudo systemctl daemon-reload
sudo systemctl enable --now apk-service
sudo systemctl status apk-service
```

## نکات امنیتی و عملیاتی

- `KEYSTORE_DIR` باید permission محدود داشته باشد (`700` برای پوشه، `600`
  برای فایل‌های `.jks`؛ سرویس خودش موقع ساخت هر فایل `chmod 600` می‌زند).
- **بکاپ‌گیری از `KEYSTORE_DIR` حیاتی است.** اگر keystore یک کاربر گم شود،
  دیگر نمی‌توانید آپدیت امضاشده با همان کلید برای او بسازید و کاربر باید اپ
  را کامل حذف و دوباره نصب کند (چون Android امضای متفاوت را رد می‌کند).
  یک cronjob برای sync دورهٔ این پوشه به یک storage جدا (مثلاً S3 یا یک سرور دیگر) توصیه می‌شود.
- سرویس را پشت یک لایه احراز هویت (API key، mTLS، یا حداقل یک reverse proxy
  با IP allowlist) قرار دهید؛ مستقیم روی اینترنت باز نگذارید.
- `KEYSTORE_PASS` را در فایل env با دسترسی محدود نگه دارید، نه در کد یا git.
- تعداد فایل‌های `.jks` به تعداد کاربران رشد می‌کند؛ برای مقیاس بسیار بزرگ
  (میلیون‌ها کاربر) شاید بخواهید keystoreها را در یک storage خارجی (مثلاً
  یک دیتابیس یا S3) نگه دارید به‌جای دیسک محلی — فعلاً برای سادگی از فایل‌سیستم
  محلی استفاده شده.
- فایل‌های APK ساخته‌شده در `OUTPUT_DIR` روی سرور **نگه‌داری می‌شوند** تا از
  طریق لینک دانلود در دسترس بمانند. این پوشه به‌مرور بزرگ می‌شود؛ یک cronjob
  برای پاکسازی فایل‌های قدیمی (مثلاً `find OUTPUT_DIR -mtime +7 -delete`) توصیه
  می‌شود.
- endpoint `/download/<file>` هر فایل موجود در `OUTPUT_DIR` را سرو می‌کند و نام
  فایل برای جلوگیری از path traversal پاک‌سازی می‌شود؛ اگر لینک‌های دانلود نباید
  عمومی باشند، این مسیر را هم پشت همان لایهٔ احراز هویت قرار دهید.
