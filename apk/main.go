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
//	BASE_APK_PATH     مسیر فایل APK پایه (دیفالت: ./base.apk)
//	OUTPUT_DIR        مسیر خروجی APKهای امضاشده (دیفالت: ./output)
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
//
// استفاده:
//
//	POST /generate   بدنه JSON: {"user_id": "u123", "token": "USER_TOKEN_VALUE"}
//	پاسخ: فایل APK امضاشده با کلید مخصوص همان user_id
//
// یا:
//
//	GET /generate?user_id=u123&token=USER_TOKEN_VALUE
//
// اگر user_id ارسال نشود، خودِ token به‌عنوان شناسهٔ کلید استفاده می‌شود
// (یعنی هر توکن یک کلید مجزا می‌گیرد).
package main

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
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
	}
}

type generateRequest struct {
	UserID string `json:"user_id"`
	Token  string `json:"token"`
}

func main() {
	cfg = loadConfig()

	if cfg.KeystoreDir == "" || cfg.KeystorePass == "" {
		log.Fatal("KEYSTORE_DIR و KEYSTORE_PASS باید تنظیم شده باشند")
	}
	if _, err := os.Stat(cfg.BaseAPKPath); err != nil {
		log.Fatalf("فایل BASE_APK_PATH پیدا نشد: %v", err)
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

	mux := http.NewServeMux()
	mux.HandleFunc("/generate", handleGenerate)
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		w.Write([]byte("ok"))
	})

	srv := &http.Server{
		Addr:         cfg.ListenAddr,
		Handler:      mux,
		ReadTimeout:  30 * time.Second,
		WriteTimeout: 180 * time.Second,
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
	defer os.RemoveAll(filepath.Dir(apkPath)) // پاکسازی دایرکتوری موقت کاری

	f, err := os.Open(apkPath)
	if err != nil {
		http.Error(w, "خطا در باز کردن فایل خروجی", http.StatusInternalServerError)
		return
	}
	defer f.Close()

	w.Header().Set("Content-Type", "application/vnd.android.package-archive")
	w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=%q", filepath.Base(apkPath)))
	if _, err := io.Copy(w, f); err != nil {
		log.Printf("خطا در ارسال فایل خروجی: %v", err)
	}
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

	return signedPath, nil
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

func runCmd(dir, name string, args ...string) error {
	cmd := exec.Command(name, args...)
	cmd.Dir = dir
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s %v: %w\noutput: %s", name, args, err, string(out))
	}
	return nil
}
