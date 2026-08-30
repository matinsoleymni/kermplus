// apk-service: سرویس ساده برای تزریق توکن کاربر داخل APK، zipalign و امضا
// با یک کلید امضای مجزا برای هر کاربر.
//
// این سرویس فرض می‌کند ابزارهای زیر روی سیستم نصب و در PATH موجودند:
//   - zip / unzip
//   - keytool    (بخشی از JDK)
//   - zipalign   (از Android SDK build-tools)
//   - apksigner  (از Android SDK build-tools)
//
// منطق کلید هر کاربر:
//
//	به‌ازای هر user_id یک keystore جداگانه در KEYSTORE_DIR ساخته و نگه‌داری می‌شود
//	(مسیر: KEYSTORE_DIR/<user_id>.jks). دفعهٔ اول درخواست برای یک کاربر، کلید
//	به‌صورت خودکار ساخته می‌شود؛ درخواست‌های بعدی همان کلید موجود را دوباره
//	استفاده می‌کنند. تمام keystoreها با یک storepass/keypass مشترک (از env) محافظت
//	می‌شوند تا مدیریت پسورد ساده بماند؛ چیزی که بین کاربران فرق می‌کند خودِ
//	جفت‌کلید (keypair) است، نه پسورد.
//
// تنظیمات از طریق متغیرهای محیطی:
//
//	BASE_APK_PATH     مسیر فایل APK پایه (دیفالت: ./base.apk — کنار خودِ سرویس)
//	OUTPUT_DIR        مسیر خروجی APKهای امضاشده که روی سرور نگه‌داری می‌شوند (دیفالت: ./output)
//	ASSET_PATH        مسیر فایل توکن داخل APK (دیفالت: assets/token.txt)
//	KEYSTORE_DIR      مسیر پوشه‌ای که keystoreهای هر کاربر در آن نگه‌داری می‌شود (الزامی)
//	KEYSTORE_PASS     پسورد مشترک برای storepass و keypass تمام keystoreها (الزامی)
//	KEY_VALIDITY_DAYS اعتبار کلید به روز (دیفالت: 10000)
//	KEY_DNAME_OU      مقدار OU در Distinguished Name (دیفالت: App)
//	KEY_DNAME_O       مقدار O در Distinguished Name (دیفالت: Company)
//	KEY_DNAME_L       مقدار L در Distinguished Name (دیفالت: City)
//	KEY_DNAME_S       مقدار ST در Distinguished Name (دیفالت: State)
//	KEY_DNAME_C       مقدار C در Distinguished Name (دیفالت: IR)
//	LISTEN_ADDR       آدرس listen (دیفالت: :8080)
//	PUBLIC_BASE_URL   آدرس عمومی سرویس برای ساخت لینک دانلود (مثلاً https://apk.example.com).
//	                  اگر خالی باشد از Host خودِ درخواست استفاده می‌شود.
//	CALLBACK_URL      آدرس سرویس دیگری که پس از آماده‌شدن نتیجهٔ اسکن با یک POST
//	                  حاوی لینک دانلود و نتیجهٔ VirusTotal مطلع می‌شود (اختیاری).
//	ACTIVATION_URL    آدرس API غیرفعال‌کردن اکتیویشن که پس از ساخت APK با هدر
//	                  X-App-Key (توکن کاربر) و بدنهٔ {"active": false} فراخوانی می‌شود
//	                  (دیفالت: https://app.kermplus.top/api/app/activation)
//	VT_API_KEY        کلید API ویروس‌توتال (اختیاری؛ اگر خالی باشد اسکن غیرفعال است)
//	VT_POLL_TIMEOUT   حداکثر زمان انتظار برای آماده‌شدن نتیجه (ثانیه یا مثل 5m، دیفالت: 5m)
//	VT_POLL_INTERVAL  فاصلهٔ بین هر بار چک‌کردن نتیجه (ثانیه یا مثل 15s، دیفالت: 15s)
//
// استفاده:
//
//	POST /generate   بدنه JSON: {"user_id": "u123", "token": "USER_TOKEN_VALUE"}
//	GET  /generate?user_id=u123&token=USER_TOKEN_VALUE
//
//	پاسخ بلافاصله یک JSON با لینک دانلود APK امضاشده است؛ خودِ فایل روی سرور
//	ذخیره می‌ماند و از طریق GET /download/<file> قابل دریافت است. اگر VT_API_KEY
//	تنظیم شده باشد، اسکن VirusTotal به‌صورت پس‌زمینه انجام می‌شود و نتیجه پس از
//	آماده‌شدن با یک POST به CALLBACK_URL اطلاع داده می‌شود (چون اسکن ممکن است
//	زمان‌بر باشد و درخواست اصلی منتظر آن نمی‌ماند).
//
//	اگر user_id ارسال نشود، خودِ token به‌عنوان شناسهٔ کلید استفاده می‌شود
//	(یعنی هر توکن یک کلید مجزا می‌گیرد).
//
//	GET  /download/<file>   دانلود یک APK ساخته‌شده از OUTPUT_DIR.
//	POST /scan              فرم multipart با فیلد "file": یک فایل دلخواه را اسکن می‌کند.
package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"mime/multipart"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
)

type config struct {
	BaseAPKPath     string
	OutputDir       string
	AssetPath       string
	KeystoreDir     string
	KeystorePass    string
	KeyValidityDays string
	DnameOU         string
	DnameO          string
	DnameL          string
	DnameS          string
	DnameC          string
	ListenAddr      string
	PublicBaseURL   string
	CallbackURL     string
	ActivationURL   string

	VTApiKey       string
	VTPollTimeout  time.Duration
	VTPollInterval time.Duration
}

const userKeyAlias = "user-key"

var cfg config

var safeID = regexp.MustCompile(`[^a-zA-Z0-9_\-.]+`)

var userLocks sync.Map // map[string]*sync.Mutex

func loadConfig() config {
	get := func(key, def string) string {
		if v := os.Getenv(key); v != "" {
			return v
		}
		return def
	}
	return config{
		BaseAPKPath:     get("BASE_APK_PATH", "./base.apk"),
		OutputDir:       get("OUTPUT_DIR", "./output"),
		AssetPath:       get("ASSET_PATH", "assets/token.txt"),
		KeystoreDir:     get("KEYSTORE_DIR", ""),
		KeystorePass:    get("KEYSTORE_PASS", ""),
		KeyValidityDays: get("KEY_VALIDITY_DAYS", "10000"),
		DnameOU:         get("KEY_DNAME_OU", "App"),
		DnameO:          get("KEY_DNAME_O", "Company"),
		DnameL:          get("KEY_DNAME_L", "City"),
		DnameS:          get("KEY_DNAME_S", "State"),
		DnameC:          get("KEY_DNAME_C", "IR"),
		ListenAddr:      get("LISTEN_ADDR", ":8080"),
		PublicBaseURL:   strings.TrimRight(get("PUBLIC_BASE_URL", ""), "/"),
		CallbackURL:     get("CALLBACK_URL", ""),
		ActivationURL:   get("ACTIVATION_URL", "https://app.kermplus.top/api/app/activation"),

		VTApiKey:       get("VT_API_KEY", ""),
		VTPollTimeout:  getDuration("VT_POLL_TIMEOUT", 5*time.Minute),
		VTPollInterval: getDuration("VT_POLL_INTERVAL", 15*time.Second),
	}
}

func getDuration(key string, def time.Duration) time.Duration {
	v := os.Getenv(key)
	if v == "" {
		return def
	}
	if n, err := strconv.Atoi(v); err == nil {
		return time.Duration(n) * time.Second
	}
	if d, err := time.ParseDuration(v); err == nil {
		return d
	}
	return def
}

type generateRequest struct {
	UserID string `json:"user_id"`
	Token  string `json:"token"`
}

// generateResponse پاسخ بلافاصلهٔ /generate است؛ خودِ فایل روی سرور می‌ماند و
// از طریق download_url قابل دریافت است. اسکن VirusTotal (در صورت فعال‌بودن) بعداً
// در پس‌زمینه انجام و نتیجه‌اش به CALLBACK_URL فرستاده می‌شود.
type generateResponse struct {
	UserID      string `json:"user_id"`
	File        string `json:"file"`
	DownloadURL string `json:"download_url"`
	Status      string `json:"status"` // scanning | no_scan
}

func main() {
	cfg = loadConfig()

	if cfg.KeystoreDir == "" || cfg.KeystorePass == "" {
		log.Fatal("KEYSTORE_DIR و KEYSTORE_PASS باید تنظیم شده باشند")
	}
	if _, err := os.Stat(cfg.BaseAPKPath); err != nil {
		log.Printf("هشدار: فایل BASE_APK_PATH (%s) پیدا نشد — درخواست‌ها شکست می‌خورند", cfg.BaseAPKPath)
	}
	if err := os.MkdirAll(cfg.OutputDir, 0755); err != nil {
		log.Fatalf("امکان ساخت OUTPUT_DIR وجود ندارد: %v", err)
	}
	if err := os.MkdirAll(cfg.KeystoreDir, 0700); err != nil {
		log.Fatalf("امکان ساخت KEYSTORE_DIR وجود ندارد: %v", err)
	}
	for _, tool := range []string{"zip", "unzip", "keytool", "zipalign", "apksigner"} {
		if _, err := exec.LookPath(tool); err != nil {
			log.Fatalf("ابزار %q در PATH پیدا نشد", tool)
		}
	}

	if cfg.VTApiKey == "" {
		log.Printf("هشدار: VT_API_KEY تنظیم نشده — اسکن VirusTotal غیرفعال است")
	}
	if cfg.CallbackURL == "" {
		log.Printf("هشدار: CALLBACK_URL تنظیم نشده — نتیجهٔ اسکن به سرویس دیگری اطلاع داده نمی‌شود")
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/generate", handleGenerate)
	mux.HandleFunc("/download/", handleDownload)
	mux.HandleFunc("/scan", handleScan)
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		w.Write([]byte("ok"))
	})

	// آپلود فایل‌های حجیم روی /scan می‌تواند طولانی باشد، پس timeoutها را
	// سخاوتمندانه تنظیم می‌کنیم تا اتصال وسط آپلود قطع نشود. (اسکن /generate
	// در پس‌زمینه انجام می‌شود و به این timeoutها وابسته نیست.)
	readTimeout := 10 * time.Minute
	writeTimeout := cfg.VTPollTimeout + 10*time.Minute

	srv := &http.Server{
		Addr:         cfg.ListenAddr,
		Handler:      mux,
		ReadTimeout:  readTimeout,
		WriteTimeout: writeTimeout,
	}

	log.Printf("apk-service در حال گوش‌دادن روی %s", cfg.ListenAddr)
	log.Fatal(srv.ListenAndServe())
}

func handleGenerate(w http.ResponseWriter, r *http.Request) {
	var req generateRequest

	switch r.Method {
	case http.MethodGet:
		req.UserID = r.URL.Query().Get("user_id")
		req.Token = r.URL.Query().Get("token")
	case http.MethodPost:
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			http.Error(w, "بدنه JSON نامعتبر است", http.StatusBadRequest)
			return
		}
	default:
		http.Error(w, "متد پشتیبانی نمی‌شود", http.StatusMethodNotAllowed)
		return
	}

	if req.Token == "" {
		http.Error(w, "پارامتر token الزامی است", http.StatusBadRequest)
		return
	}
	userID := req.UserID
	if userID == "" {
		userID = req.Token
	}

	apkPath, err := buildSignedAPK(userID, req.Token)
	if err != nil {
		log.Printf("خطا در ساخت APK برای کاربر %q: %v", userID, err)
		http.Error(w, "خطای داخلی سرور در ساخت APK", http.StatusInternalServerError)
		return
	}

	filename := filepath.Base(apkPath)
	downloadURL := buildDownloadURL(r, filename)

	resp := generateResponse{
		UserID:      userID,
		File:        filename,
		DownloadURL: downloadURL,
		Status:      "no_scan",
	}

	if err := setActivation(req.Token, false); err != nil {
		log.Printf("خطا در غیرفعال‌کردن اکتیویشن برای کاربر %q: %v", userID, err)
	}

	if cfg.VTApiKey != "" {
		resp.Status = "scanning"
		go scanAndNotify(userID, apkPath, filename, downloadURL)
	}

	writeJSON(w, http.StatusOK, resp)
}

func buildDownloadURL(r *http.Request, filename string) string {
	base := cfg.PublicBaseURL
	if base == "" {
		scheme := "http"
		if r.TLS != nil {
			scheme = "https"
		}
		if p := r.Header.Get("X-Forwarded-Proto"); p != "" {
			scheme = p
		}
		base = scheme + "://" + r.Host
	}
	return base + "/download/" + filename
}

func handleDownload(w http.ResponseWriter, r *http.Request) {
	name := strings.TrimPrefix(r.URL.Path, "/download/")
	name = filepath.Base(name) // جلوگیری از path traversal
	if name == "" || name == "." || name == ".." {
		http.NotFound(w, r)
		return
	}
	path := filepath.Join(cfg.OutputDir, name)
	if _, err := os.Stat(path); err != nil {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Content-Type", "application/vnd.android.package-archive")
	w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=%q", name))
	http.ServeFile(w, r, path)
}

func scanAndNotify(userID, apkPath, filename, downloadURL string) {
	ctx, cancel := context.WithTimeout(context.Background(), cfg.VTPollTimeout+2*time.Minute)
	defer cancel()

	payload := callbackPayload{
		UserID:      userID,
		File:        filename,
		DownloadURL: downloadURL,
	}

	result, err := scanFileWithVT(ctx, apkPath)
	if err != nil {
		log.Printf("خطا در اسکن VirusTotal برای کاربر %q: %v", userID, err)
		payload.Error = err.Error()
	} else {
		payload.Scan = result
	}

	notifyCallback(payload)
}

// callbackPayload بدنه‌ای است که پس از اتمام اسکن به CALLBACK_URL POST می‌شود.
type callbackPayload struct {
	UserID      string      `json:"user_id"`
	File        string      `json:"file"`
	DownloadURL string      `json:"download_url"`
	Scan        *scanResult `json:"scan,omitempty"`
	Error       string      `json:"error,omitempty"`
}

// notifyCallback نتیجهٔ اسکن را با یک POST به سرویس دیگر اطلاع می‌دهد.
func notifyCallback(payload callbackPayload) {
	if cfg.CallbackURL == "" {
		return
	}

	body, err := json.Marshal(payload)
	if err != nil {
		log.Printf("خطا در سریال‌سازی callback: %v", err)
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, cfg.CallbackURL, bytes.NewReader(body))
	if err != nil {
		log.Printf("خطا در ساخت درخواست callback: %v", err)
		return
	}
	req.Header.Set("Content-Type", "application/json")

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		log.Printf("خطا در ارسال callback به %s: %v", cfg.CallbackURL, err)
		return
	}
	defer resp.Body.Close()
	io.Copy(io.Discard, io.LimitReader(resp.Body, 1<<20))

	if resp.StatusCode >= 300 {
		log.Printf("callback به %s پاسخ %d برگرداند", cfg.CallbackURL, resp.StatusCode)
		return
	}
	log.Printf("نتیجهٔ اسکن برای فایل %s به سرویس callback اطلاع داده شد", payload.File)
}

func setActivation(token string, active bool) error {
	if cfg.ActivationURL == "" {
		return nil
	}

	body, err := json.Marshal(map[string]bool{"active": active})
	if err != nil {
		return err
	}

	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, cfg.ActivationURL, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-App-Key", token)

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	io.Copy(io.Discard, io.LimitReader(resp.Body, 1<<20))

	if resp.StatusCode >= 300 {
		return fmt.Errorf("activation پاسخ %d برگرداند", resp.StatusCode)
	}
	return nil
}

func buildSignedAPK(userID, token string) (string, error) {
	keystorePath, err := ensureUserKeystore(userID)
	if err != nil {
		return "", fmt.Errorf("آماده‌سازی کلید کاربر ناموفق بود: %w", err)
	}

	workDir, err := os.MkdirTemp("", "apkbuild-*")
	if err != nil {
		return "", fmt.Errorf("ساخت دایرکتوری موقت ناموفق بود: %w", err)
	}
	defer os.RemoveAll(workDir)

	repackaged := filepath.Join(workDir, "repackaged.apk")
	aligned := filepath.Join(workDir, "aligned.apk")

	if err := copyFile(cfg.BaseAPKPath, repackaged); err != nil {
		return "", fmt.Errorf("کپی APK پایه ناموفق بود: %w", err)
	}

	assetDir := filepath.Join(workDir, "asset_staging", filepath.Dir(cfg.AssetPath))
	if err := os.MkdirAll(assetDir, 0755); err != nil {
		return "", fmt.Errorf("ساخت پوشه asset ناموفق بود: %w", err)
	}
	assetFile := filepath.Join(workDir, "asset_staging", cfg.AssetPath)
	if err := os.WriteFile(assetFile, []byte(token), 0644); err != nil {
		return "", fmt.Errorf("نوشتن فایل توکن ناموفق بود: %w", err)
	}

	if err := runCmd(filepath.Join(workDir, "asset_staging"), "zip", repackaged, cfg.AssetPath); err != nil {
		return "", fmt.Errorf("بروزرسانی فایل توکن در APK ناموفق بود: %w", err)
	}

	if err := runCmd("", "zipalign", "-f", "-p", "4", repackaged, aligned); err != nil {
		return "", fmt.Errorf("zipalign ناموفق بود: %w", err)
	}

	safeUser := safeID.ReplaceAllString(userID, "_")
	if len(safeUser) > 60 {
		safeUser = safeUser[:60]
	}
	signedName := fmt.Sprintf("user_%s_%d.apk", safeUser, time.Now().UnixNano())
	signedPath := filepath.Join(workDir, signedName)

	signArgs := []string{
		"sign",
		"--ks", keystorePath,
		"--ks-key-alias", userKeyAlias,
		"--ks-pass", "pass:" + cfg.KeystorePass,
		"--key-pass", "pass:" + cfg.KeystorePass,
		"--out", signedPath,
		aligned,
	}
	if err := runCmd("", "apksigner", signArgs...); err != nil {
		return "", fmt.Errorf("امضای APK ناموفق بود: %w", err)
	}

	if err := runCmd("", "apksigner", "verify", signedPath); err != nil {
		return "", fmt.Errorf("راستی‌آزمایی امضا ناموفق بود: %w", err)
	}

	// فایل نهایی را به OUTPUT_DIR منتقل می‌کنیم تا روی سرور نگه‌داری شود و از
	// طریق لینک دانلود در دسترس بماند (دایرکتوری موقت با defer پاک می‌شود).
	finalPath := filepath.Join(cfg.OutputDir, signedName)
	if err := moveFile(signedPath, finalPath); err != nil {
		return "", fmt.Errorf("انتقال APK به OUTPUT_DIR ناموفق بود: %w", err)
	}

	return finalPath, nil
}

func ensureUserKeystore(userID string) (string, error) {
	safeUser := safeID.ReplaceAllString(userID, "_")
	if safeUser == "" {
		return "", fmt.Errorf("شناسهٔ کاربر پس از پاک‌سازی خالی شد")
	}
	keystorePath := filepath.Join(cfg.KeystoreDir, safeUser+".jks")

	if _, err := os.Stat(keystorePath); err == nil {
		return keystorePath, nil
	}

	lockIface, _ := userLocks.LoadOrStore(safeUser, &sync.Mutex{})
	lock := lockIface.(*sync.Mutex)
	lock.Lock()
	defer lock.Unlock()

	if _, err := os.Stat(keystorePath); err == nil {
		return keystorePath, nil
	}

	dname := fmt.Sprintf("CN=%s,OU=%s,O=%s,L=%s,ST=%s,C=%s",
		safeUser, cfg.DnameOU, cfg.DnameO, cfg.DnameL, cfg.DnameS, cfg.DnameC)

	tmpPath := keystorePath + ".tmp"
	os.Remove(tmpPath) // پاکسازی باقیمانده‌های احتمالی از تلاش قبلی ناموفق

	args := []string{
		"-genkeypair",
		"-keystore", tmpPath,
		"-alias", userKeyAlias,
		"-keyalg", "RSA",
		"-keysize", "2048",
		"-validity", cfg.KeyValidityDays,
		"-storepass", cfg.KeystorePass,
		"-keypass", cfg.KeystorePass,
		"-dname", dname,
		"-noprompt",
	}
	if err := runCmd("", "keytool", args...); err != nil {
		os.Remove(tmpPath)
		return "", fmt.Errorf("ساخت کلید جدید برای کاربر %q ناموفق بود: %w", userID, err)
	}

	if err := os.Chmod(tmpPath, 0600); err != nil {
		os.Remove(tmpPath)
		return "", fmt.Errorf("تنظیم مجوز فایل کلید ناموفق بود: %w", err)
	}
	if err := os.Rename(tmpPath, keystorePath); err != nil {
		os.Remove(tmpPath)
		return "", fmt.Errorf("جابه‌جایی نهایی فایل کلید ناموفق بود: %w", err)
	}

	log.Printf("کلید امضای جدید برای کاربر %q ساخته شد: %s", userID, keystorePath)
	return keystorePath, nil
}

func copyFile(src, dst string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()

	out, err := os.Create(dst)
	if err != nil {
		return err
	}
	defer out.Close()

	_, err = io.Copy(out, in)
	return err
}

func moveFile(src, dst string) error {
	if err := os.Rename(src, dst); err == nil {
		return nil
	}
	if err := copyFile(src, dst); err != nil {
		return err
	}
	return os.Remove(src)
}

func runCmd(dir, name string, args ...string) error {
	cmd := exec.Command(name, args...)
	cmd.Dir = dir
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s %v: %w\noutput: %s", name, args, err, string(out))
	}
	return nil
}

const vtDirectUploadLimit = 32 * 1024 * 1024

const vtBaseURL = "https://www.virustotal.com/api/v3"

var vtHTTPClient = &http.Client{Timeout: 5 * time.Minute}

type scanResult struct {
	AnalysisID string         `json:"analysis_id"`
	SHA256     string         `json:"sha256,omitempty"`
	Status     string         `json:"status"`          // completed | queued | timeout
	Verdict    string         `json:"verdict"`         // clean | suspicious | malicious | unknown
	Malicious  int            `json:"malicious"`       // تعداد آنتی‌ویروس‌هایی که بدافزار تشخیص دادند
	Suspicious int            `json:"suspicious"`      //
	Harmless   int            `json:"harmless"`        //
	Undetected int            `json:"undetected"`      //
	Stats      map[string]int `json:"stats,omitempty"` // خام آمار موتورها
	Permalink  string         `json:"permalink,omitempty"`
}

// scanFileWithVT فایل را به VirusTotal آپلود کرده و تا آماده‌شدن نتیجه poll می‌کند.
func scanFileWithVT(ctx context.Context, path string) (*scanResult, error) {
	analysisID, err := vtUploadFile(ctx, path)
	if err != nil {
		return nil, fmt.Errorf("آپلود به VirusTotal ناموفق بود: %w", err)
	}

	deadline := time.Now().Add(cfg.VTPollTimeout)
	for {
		res, done, err := vtGetAnalysis(ctx, analysisID)
		if err != nil {
			return nil, fmt.Errorf("دریافت نتیجهٔ تحلیل ناموفق بود: %w", err)
		}
		if done {
			return res, nil
		}
		if time.Now().After(deadline) {
			// نتیجه هنوز آماده نیست؛ شناسه را برمی‌گردانیم تا کلاینت بعداً چک کند.
			res.Status = "timeout"
			return res, nil
		}
		select {
		case <-ctx.Done():
			return nil, ctx.Err()
		case <-time.After(cfg.VTPollInterval):
		}
	}
}

// vtUploadFile فایل را با انتخاب خودکار endpoint مناسب (بسته به اندازه) آپلود می‌کند
// و analysis ID را برمی‌گرداند.
func vtUploadFile(ctx context.Context, path string) (string, error) {
	info, err := os.Stat(path)
	if err != nil {
		return "", err
	}

	uploadURL := vtBaseURL + "/files"
	if info.Size() > vtDirectUploadLimit {
		uploadURL, err = vtGetUploadURL(ctx)
		if err != nil {
			return "", fmt.Errorf("دریافت upload_url برای فایل بزرگ ناموفق بود: %w", err)
		}
	}

	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()

	pr, pw := io.Pipe()
	mw := multipart.NewWriter(pw)
	go func() {
		part, err := mw.CreateFormFile("file", filepath.Base(path))
		if err != nil {
			pw.CloseWithError(err)
			return
		}
		if _, err := io.Copy(part, f); err != nil {
			pw.CloseWithError(err)
			return
		}
		pw.CloseWithError(mw.Close())
	}()

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, uploadURL, pr)
	if err != nil {
		return "", err
	}
	req.Header.Set("x-apikey", cfg.VTApiKey)
	req.Header.Set("Content-Type", mw.FormDataContentType())

	resp, err := vtHTTPClient.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("پاسخ %d از VirusTotal: %s", resp.StatusCode, string(body))
	}

	var parsed struct {
		Data struct {
			ID string `json:"id"`
		} `json:"data"`
	}
	if err := json.Unmarshal(body, &parsed); err != nil {
		return "", fmt.Errorf("پاسخ آپلود نامعتبر بود: %w", err)
	}
	if parsed.Data.ID == "" {
		return "", fmt.Errorf("analysis id در پاسخ آپلود یافت نشد: %s", string(body))
	}
	return parsed.Data.ID, nil
}

// vtGetUploadURL برای فایل‌های بزرگ‌تر از ۳۲MB یک URL آپلود موقت می‌گیرد.
func vtGetUploadURL(ctx context.Context) (string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, vtBaseURL+"/files/upload_url", nil)
	if err != nil {
		return "", err
	}
	req.Header.Set("x-apikey", cfg.VTApiKey)

	resp, err := vtHTTPClient.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("پاسخ %d: %s", resp.StatusCode, string(body))
	}
	var parsed struct {
		Data string `json:"data"`
	}
	if err := json.Unmarshal(body, &parsed); err != nil || parsed.Data == "" {
		return "", fmt.Errorf("upload_url نامعتبر بود: %s", string(body))
	}
	return parsed.Data, nil
}

// vtGetAnalysis وضعیت یک تحلیل را می‌گیرد. done=true یعنی تحلیل کامل شده است.
func vtGetAnalysis(ctx context.Context, analysisID string) (*scanResult, bool, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, vtBaseURL+"/analyses/"+analysisID, nil)
	if err != nil {
		return nil, false, err
	}
	req.Header.Set("x-apikey", cfg.VTApiKey)

	resp, err := vtHTTPClient.Do(req)
	if err != nil {
		return nil, false, err
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
	if resp.StatusCode != http.StatusOK {
		return nil, false, fmt.Errorf("پاسخ %d: %s", resp.StatusCode, string(body))
	}

	log.Printf("پاسخ تحلیل VirusTotal: %s", string(body))

	var parsed struct {
		Data struct {
			ID         string `json:"id"`
			Attributes struct {
				Status string         `json:"status"`
				Stats  map[string]int `json:"stats"`
			} `json:"attributes"`
		} `json:"data"`
		Meta struct {
			FileInfo struct {
				SHA256 string `json:"sha256"`
			} `json:"file_info"`
		} `json:"meta"`
	}
	if err := json.Unmarshal(body, &parsed); err != nil {
		return nil, false, fmt.Errorf("پاسخ تحلیل نامعتبر بود: %w", err)
	}

	stats := parsed.Data.Attributes.Stats
	res := &scanResult{
		AnalysisID: analysisID,
		SHA256:     parsed.Meta.FileInfo.SHA256,
		Status:     parsed.Data.Attributes.Status,
		Malicious:  stats["malicious"],
		Suspicious: stats["suspicious"],
		Harmless:   stats["harmless"],
		Undetected: stats["undetected"],
		Stats:      stats,
	}
	if res.SHA256 != "" {
		res.Permalink = "https://www.virustotal.com/gui/file/" + res.SHA256
	}
	res.Verdict = verdictFromStats(res)

	done := parsed.Data.Attributes.Status == "completed"
	return res, done, nil
}

// verdictFromStats یک برچسب کلی از آمار موتورها استخراج می‌کند.
func verdictFromStats(r *scanResult) string {
	switch {
	case r.Status != "completed":
		return "unknown"
	case r.Malicious > 0:
		return "malicious"
	case r.Suspicious > 0:
		return "suspicious"
	default:
		return "clean"
	}
}

// handleScan یک فایل دلخواه (multipart form با فیلد "file") را می‌گیرد، به
// VirusTotal می‌فرستد و نتیجه را به‌صورت JSON برمی‌گرداند.
func handleScan(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "متد پشتیبانی نمی‌شود", http.StatusMethodNotAllowed)
		return
	}
	if cfg.VTApiKey == "" {
		http.Error(w, "اسکن VirusTotal فعال نیست (VT_API_KEY تنظیم نشده)", http.StatusServiceUnavailable)
		return
	}

	file, hdr, err := r.FormFile("file")
	if err != nil {
		http.Error(w, "فیلد فایل ('file') در فرم یافت نشد", http.StatusBadRequest)
		return
	}
	defer file.Close()

	tmp, err := os.CreateTemp("", "vtscan-*-"+safeID.ReplaceAllString(hdr.Filename, "_"))
	if err != nil {
		http.Error(w, "خطای داخلی سرور", http.StatusInternalServerError)
		return
	}
	tmpPath := tmp.Name()
	defer os.Remove(tmpPath)

	if _, err := io.Copy(tmp, file); err != nil {
		tmp.Close()
		http.Error(w, "خطا در ذخیرهٔ فایل آپلودی", http.StatusInternalServerError)
		return
	}
	tmp.Close()

	result, err := scanFileWithVT(r.Context(), tmpPath)
	if err != nil {
		log.Printf("خطا در اسکن VirusTotal: %v", err)
		http.Error(w, "خطا در اسکن VirusTotal: "+err.Error(), http.StatusBadGateway)
		return
	}
	writeJSON(w, http.StatusOK, result)
}

func writeJSON(w http.ResponseWriter, status int, v interface{}) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	enc := json.NewEncoder(w)
	enc.SetIndent("", "  ")
	if err := enc.Encode(v); err != nil {
		log.Printf("خطا در نوشتن پاسخ JSON: %v", err)
	}
}
