package services

import (
	"fmt"
	"strings"
	"time"

	"form/configs"

	"github.com/go-rod/rod"
	"github.com/go-rod/rod/lib/launcher"
	"github.com/go-rod/rod/lib/proto"
)

type Mode int

const (
	ModeFillForm Mode = iota
	ModeRegister
)

type GuessResult struct {
	Value  string
	Reason string
}

type Result struct {
	Status   bool              `json:"status"`
	Message  string            `json:"message"`
	Logs     []string          `json:"logs,omitempty"`
	SentData map[string]string `json:"sent_data,omitempty"`
}

type BatchResult struct {
	Total    int       `json:"total"`
	Success  int       `json:"success"`
	Failed   int       `json:"failed"`
	Results  []*Result `json:"results,omitempty"`
	Duration string    `json:"duration"`
	Errors   []string  `json:"errors,omitempty"`
}

type AutoFormFiller struct {
	browser    *rod.Browser
	fixedPhone string
	fixedName  string
	fixedEmail string
	debug      bool
	headless   bool
	logs       []string
	mode       Mode
	timeout    time.Duration
}

type Option func(*AutoFormFiller)

func WithDebug(enabled bool) Option {
	return func(af *AutoFormFiller) { af.debug = enabled }
}

func WithHeadless(enabled bool) Option {
	return func(af *AutoFormFiller) { af.headless = enabled }
}

func WithTimeout(timeout time.Duration) Option {
	return func(af *AutoFormFiller) { af.timeout = timeout }
}

func NewAutoFormFiller(opts ...Option) (*AutoFormFiller, error) {
	af := &AutoFormFiller{
		logs:     make([]string, 0),
		timeout:  60 * time.Second,
		headless: true, 
	}

	for _, opt := range opts {
		opt(af)
	}

	u, err := launcher.New().
		Headless(af.headless).
		Set("disable-blink-features", "AutomationControlled").
		Launch()
	if err != nil {
		return nil, fmt.Errorf("could not launch browser: %v", err)
	}

	browser := rod.New().ControlURL(u).MustConnect()
	browser.IgnoreCertErrors(true)
	af.browser = browser

	return af, nil
}

func (af *AutoFormFiller) Close() error {
	return af.browser.Close()
}

func (af *AutoFormFiller) SetMode(mode Mode) *AutoFormFiller {
	af.mode = mode
	return af
}

func (af *AutoFormFiller) SubmitForm(targetURL, phoneNumber string, targetName ...string) *Result {
	af.fixedPhone = phoneNumber
	af.logs = make([]string, 0)

	if len(targetName) > 0 && targetName[0] != "" {
		af.fixedName = targetName[0]
	}

	af.log("==========================================")
	af.log("URL: %s", targetURL)

	page, err := af.browser.Page(proto.TargetCreateTarget{URL: targetURL})
	if err != nil {
		return af.result(false, fmt.Sprintf("خطا در ایجاد صفحه: %v", err))
	}
	// دقت کنید: defer page.MustClose() کامنت شده تا صفحه برای وارد کردن OTP باز بماند!
	// در سیستم اصلی باید مدیریت بسته شدن تب‌ها را زمانی انجام دهید که کار کاربر نهایی تمام شده است.
	// defer page.MustClose()

	// اجازه می‌دهیم صفحه لود شود
	page.MustWaitLoad()
	_ = page.WaitStable(2 * time.Second)

	workingFrame := page

	// مدیریت فرم‌های داخل iframe
	iframes, err := page.Elements("iframe")
	if err == nil {
		for _, iframeEl := range iframes {
			f, err := iframeEl.Frame()
			if err != nil {
				continue
			}
			info, err := f.Info()
			if err != nil {
				continue
			}
			fURL := strings.ToLower(info.URL)
			for _, kw := range configs.IFrameKeywords {
				if strings.Contains(fURL, kw) {
					af.log("تشخیص iframe فرم‌ساز: %s", info.URL)
					workingFrame = f
					break
				}
			}
			if workingFrame != page {
				break
			}
		}
	}

	// =========================================================================
	// گام اول: کلیک روی دکمه‌های دروازه‌ای
	// =========================================================================
	af.log("--- بررسی وجود دکمه‌های دروازه‌ای هدر (ورود / عضویت) ---")
	jsGatewayClicker := `function() {
		let targets = document.querySelectorAll('button, a, div, span, [role="button"]');
		let gatewayKeywords = [
			/ورود\s*[\/|]\s*عضویت/,
			/ثبت\s*نام/,
			/^ورود$/,
			/^عضویت$/,
			/sign\s*up/i,
			/register/i,
			/login/i,
            /ورود یا ثبت نام/,
		];

		for (let el of targets) {
			let text = el.innerText ? el.innerText.trim().toLowerCase() : '';
			if (!text || text.length > 20) continue;

			for (let kw of gatewayKeywords) {
				if (kw.test(text)) {
					let style = window.getComputedStyle(el);
					if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
						continue;
					}
					el.scrollIntoView({ block: 'center' });
					// به جای کلیک با JS، دکمه را نشانه‌گذاری می‌کنیم تا با Rod کلیک طبیعی کنیم
					el.setAttribute('data-bot-gateway', 'true');
					return "found";
				}
			}
		}
		return "";
	}`

	if gatewayRes, err := workingFrame.Eval(jsGatewayClicker); err == nil && gatewayRes != nil {
		if gatewayRes.Value.Str() == "found" {
			btn, err := workingFrame.Element("[data-bot-gateway='true']")
			if err == nil {
				af.log("🎯 دکمه دروازه‌ای هدر پیدا شد، انجام کلیک فیزیکی...")
				_ = btn.ScrollIntoView()

				// منتظر می‌مانیم تا شبکه آزاد شود
				wait := page.MustWaitRequestIdle()
				err = btn.Click(proto.InputMouseButtonLeft, 1)
				if err == nil {
					// قفل می‌شویم تا درخواست شبکه (مثل باز شدن مودال یا تغییر مسیر) تمام شود
					wait()
					_ = workingFrame.WaitStable(1 * time.Second)
				}
			}
		}
	}

	// =========================================================================
	// گام دوم: چرخه هوشمند پردازش، تزریق فیلدها و ارسال فرم
	// =========================================================================
	af.log("--- شروع پردازش هوشمند سراسری صفحه و مودال‌ها ---")
	guessedData := make(map[string]string)

	for step := 1; step <= 3; step++ {
		af.log("مرحله پردازش: %d", step)

		// بررسی لودینگ‌ها تا قبل از پر کردن فیلدها، سایت در حال پردازش نباشد
		_ = workingFrame.WaitStable(1 * time.Second)

		// تشخیص صفحه OTP قبل از هر اقدام اضافی
		if htmlContent, err := workingFrame.HTML(); err == nil {
			if af.isOTPScreen(htmlContent) {
				af.log("✅ صفحه دریافت کد تایید (OTP) تشخیص داده شد. فرم با موفقیت ارسال شده است.")
				return &Result{
					Status:   true,
					Message:  "رسیدن به مرحله کد تایید (موفق)",
					Logs:     af.logs,
					SentData: guessedData,
				}
			}
		}

		jsDeepScan := `() => {
			let all = [];
			function walk(node) {
				if (node.shadowRoot) walk(node.shadowRoot);
				let els = node.querySelectorAll ? node.querySelectorAll('input:not([type="hidden"]), textarea, select') : [];
				els.forEach(e => all.push(e));
				let children = node.children || [];
				for (let i = 0; i < children.length; i++) walk(children[i]);
			}
			walk(document.body);
			return all;
		}`

		inputs, err := workingFrame.ElementsByJS(rod.Eval(jsDeepScan))
		if err != nil {
			af.log("خطا در واکشی عمیق اینپوت‌ها: %v", err)
		}

		filledAny := false
		for i, input := range inputs {
			if visible, _ := input.Visible(); !visible {
				continue
			}

			inputType := getJSString(input, `() => this.type || ""`)
			tagName := getJSString(input, `() => this.tagName.toLowerCase()`)
			if inputType == "" {
				inputType = tagName
			}

			skipTypes := []string{"submit", "button", "image", "reset", "file", "hidden"}
			if af.containsAny(inputType, skipTypes) {
				continue
			}

			name := getJSString(input, `() => this.name || this.id || this.placeholder || ""`)
			if name == "" {
				name = fmt.Sprintf("global_step%d_field_%d", step, i)
			}

			if _, exists := guessedData[name]; exists {
				continue
			}

			contextStr := getJSString(input, `(el) => {
				let text = "";
				if (el.id) { let l = document.querySelector('label[for="'+el.id+'"]'); if(l) text += l.innerText + " "; }
				let p = el.closest('.gfield') || el.closest('.form-group') || el.closest('label') || el.closest('p') || el.parentElement;
				if (p) text += p.innerText + " ";
				if (el.placeholder) text += el.placeholder + " ";
				return text;
			}`)

			placeholder := getJSString(input, `() => this.placeholder || ""`)
			searchStr := strings.ToLower(fmt.Sprintf("%s %s %s", name, contextStr, placeholder))

			guess := af.guessValue(targetURL, name, searchStr, inputType, tagName)
			if guess != nil {
				guessedData[name] = guess.Value
				af.log("   >>> فیلد [%s] شناسایی و با موفقیت پر شد.", name)
				af.fillField(input, inputType, tagName, guess.Value)
				filledAny = true
			}
		}

		if !filledAny && step > 1 {
			af.log("میدان جدیدی پر نشد. پایان چرخه فرم.")
			break
		}

		af.log("تلاش برای پیدا کردن دکمه سابمیت فرم...")

		jsFormSubmitter := `function() {
			let btn = document.querySelector('button[type="submit"], input[type="submit"]');
			if (btn) {
				let style = window.getComputedStyle(btn);
				if (style.display !== 'none' && style.visibility !== 'hidden') {
					btn.scrollIntoView({ block: 'center' });
					btn.setAttribute('data-bot-submit', 'true');
					return "found";
				}
			}

			let targets = document.querySelectorAll('button, .btn, [role="button"], div, span, a');
			let actionKeywords = [/ادامه/, /ارسال/, /تایید/, /ورود/, /ثبت/,/بعد/,/ارسال کد/,/ادامه/, /submit/, /next/, /continue/, /verify/, /ارسال کد یکبار مصرف/, /ارسال پیامک/, /ارسال کد یک بار مصرف/, /ارسال کد تایید/, /رمز یکبار مصرف/, /دریافت کد/];

			for (let el of targets) {
				let text = el.innerText ? el.innerText.trim().toLowerCase() : '';
				if (!text || text.length > 25) continue;

				let isInsideHeader = el.closest('header') || el.closest('nav') || el.closest('.header') || el.closest('.menu');
				if (isInsideHeader) {
					continue;
				}

				for (let kw of actionKeywords) {
					if (kw.test(text)) {
						let style = window.getComputedStyle(el);
						if (style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0') {
							el.scrollIntoView({ block: 'center' });
							el.setAttribute('data-bot-submit', 'true');
							return "found";
						}
					}
				}
			}
			return "";
		}`

		clicked := false
		if clickRes, err := workingFrame.Eval(jsFormSubmitter); err == nil && clickRes != nil {
			if clickRes.Value.Str() == "found" {
				btn, err := workingFrame.Element("[data-bot-submit='true']")
				if err == nil {
					af.log("🎯 دکمه اقدام فرم پیدا شد، کلیک فیزیکی و انتظار برای دریافت پاسخ سرور...")
					_ = btn.ScrollIntoView()

					// منتظر پاسخ API (مثل ارسال اس ام اس) می‌ماند
					wait := page.MustWaitRequestIdle()
					err = btn.Click(proto.InputMouseButtonLeft, 1)
					if err == nil {
						// این خط باعث می‌شود ربات تا اتمام لودینگ/AJAX صبر کند!
						wait()
						clicked = true
						filledAny = true
					}
				}
			}
		}

		if clicked {
			// بعد از سابمیت، 15 ثانیه هوشمندانه منتظر رسیدن به صفحه OTP می‌مانیم
			if af.waitForOTP(workingFrame, 15*time.Second) {
				af.log("✅ صفحه دریافت کد تایید (OTP) با موفقیت باز شد.")
				return &Result{
					Status:   true,
					Message:  "رسیدن به مرحله کد تایید (موفق)",
					Logs:     af.logs,
					SentData: guessedData,
				}
			}
		} else {
			af.log("هیچ دکمه سابمیتی برای خود فرم در این مرحله پیدا نشد.")
			if !filledAny {
				break
			}
		}
	}

	af.log("--- پایان پردازش فرم ---")

	content := page.MustHTML()
	verification := af.verifySubmission(content, 200)

	if af.isOTPScreen(content) {
		verification.Status = true
		verification.Message = "رسیدن به مرحله کد تایید (موفق)"
	}

	return &Result{
		Status:   verification.Status,
		Message:  verification.Message,
		Logs:     af.logs,
		SentData: guessedData,
	}
}

// waitForOTP: تابع جدید برای انتظار هوشمند جهت دریافت کد پیامک
func (af *AutoFormFiller) waitForOTP(page *rod.Page, timeout time.Duration) bool {
	af.log("⏳ در حال انتظار تا حداکثر %s برای باز شدن صفحه OTP (کد تایید)...", timeout.String())

	// یک نمونه صفحه با محدودیت زمانی برای فرار از گیر کردن‌های طولانی
	timeoutPage := page.Timeout(timeout)

	for {
		// نیم ثانیه مکس در هر چرخه
		time.Sleep(500 * time.Millisecond)

		html, err := timeoutPage.HTML()
		if err != nil {
			// در صورت اتمام تایم‌اوت یا خطا خارج می‌شود
			return false
		}

		if af.isOTPScreen(html) {
			return true
		}
	}
}

func (af *AutoFormFiller) fillField(el *rod.Element, fieldType, tagName, value string) {
	if el == nil {
		return
	}

	_ = el.ScrollIntoView()
	el.MustFocus()

	if tagName == "select" {
		el.MustEval(`function(element) {
			if (!element) return;
			let options = element.querySelectorAll('option');
			for(let i=1; i<options.length; i++) {
				if(options[i].value && options[i].value !== '') {
					element.value = options[i].value;
					element.dispatchEvent(new Event('change', {bubbles: true}));
					return;
				}
			}
			if(options.length > 0) {
				element.value = options[0].value;
				element.dispatchEvent(new Event('change', {bubbles: true}));
			}
		}`)
	} else if fieldType == "checkbox" || fieldType == "radio" {
		if value == "on" || value == "true" {
			jsCheckboxFix := `function(element) {
				if (!element) return;
				if (element.checked) return;
				let nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, "checked")?.set;
				if (nativeSetter) {
					nativeSetter.call(element, true);
				} else {
					element.checked = true;
				}
				element.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
				element.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
				element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
			}`
			_, err := el.Eval(jsCheckboxFix)
			if err != nil {
				_ = el.Click(proto.InputMouseButtonLeft, 1)
			}
		}
	} else {
		err := el.Click(proto.InputMouseButtonLeft, 1)
		if err == nil {
			_ = el.SelectAllText()
			_ = el.Input("")
			_ = el.Input(value)
		}

		jsWakeUpReact := `function(el, val) {
			if (!el) return;
			let nativeInputValueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, "value")?.set;
			let nativeTextAreaValueSetter = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, "value")?.set;
			if (el.tagName.toLowerCase() === 'input' && nativeInputValueSetter) {
				nativeInputValueSetter.call(el, val);
			} else if (el.tagName.toLowerCase() === 'textarea' && nativeTextAreaValueSetter) {
				nativeTextAreaValueSetter.call(el, val);
			} else {
				el.value = val;
			}
			el.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
			el.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
			el.dispatchEvent(new Event('blur', { bubbles: true, cancelable: true }));
			el.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, cancelable: true, key: 'Enter' }));
		}`
		_, _ = el.Eval(jsWakeUpReact, value)
	}
}

func (af *AutoFormFiller) Register(targetURL, phoneNumber, name, email string) *Result {
	af.SetMode(ModeRegister)
	af.fixedEmail = email
	return af.SubmitForm(targetURL, phoneNumber, name)
}

func (af *AutoFormFiller) BatchSubmit(urls []string, phoneNumber string, targetName ...string) *BatchResult {
	start := time.Now()
	result := &BatchResult{Total: len(urls), Results: make([]*Result, 0, len(urls)), Errors: make([]string, 0)}

	for _, u := range urls {
		func() {
			defer func() {
				if r := recover(); r != nil {
					af.log("خطای جدی در سایت %s رخ داد: %v", u, r)
					result.Failed++
					result.Errors = append(result.Errors, fmt.Sprintf("%s: خطای ناشناخته (Panic: %v)", u, r))
				}
			}()

			r := af.SubmitForm(u, phoneNumber, targetName...)
			result.Results = append(result.Results, r)
			if r.Status {
				result.Success++
			} else {
				result.Failed++
				result.Errors = append(result.Errors, fmt.Sprintf("%s: %s", u, r.Message))
			}
		}()
	}
	result.Duration = time.Since(start).String()
	return result
}

func (af *AutoFormFiller) BatchRegister(urls []string, phoneNumber, name, email string) *BatchResult {
	start := time.Now()
	result := &BatchResult{Total: len(urls), Results: make([]*Result, 0, len(urls)), Errors: make([]string, 0)}

	for _, u := range urls {
		func() {
			defer func() {
				if r := recover(); r != nil {
					af.log("خطای جدی در سایت %s رخ داد: %v", u, r)
					result.Failed++
					result.Errors = append(result.Errors, fmt.Sprintf("%s: خطای ناشناخته (Panic: %v)", u, r))
				}
			}()

			r := af.Register(u, phoneNumber, name, email)
			result.Results = append(result.Results, r)
			if r.Status {
				result.Success++
			} else {
				result.Failed++
				result.Errors = append(result.Errors, fmt.Sprintf("%s: %s", u, r.Message))
			}
		}()
	}
	result.Duration = time.Since(start).String()
	return result
}

func getJSString(el *rod.Element, js string) string {
	res, err := el.Eval(js)
	if err != nil || res == nil {
		return ""
	}
	return res.Value.Str()
}

func (af *AutoFormFiller) guessValue(pageURL, name, context, fieldType, tagName string) *GuessResult {
	if tagName == "select" {
		return &GuessResult{Value: "__SELECT__", Reason: "Select"}
	}
	if fieldType == "radio" {
		return &GuessResult{Value: "on", Reason: "Radio"}
	}
	if fieldType == "checkbox" {
		ruleKeywords := []string{"rule", "term", "qavanin", "شرایط", "قوانین"}
		if af.containsAny(context, ruleKeywords) {
			return &GuessResult{Value: "on", Reason: "Rules"}
		}
		return nil
	}
	if af.containsAny(context, configs.PhoneKeywords) || fieldType == "tel" {
		timeKeywords := []string{"time", "date", "زمان", "ساعت", "محدوده", "کی"}
		if af.containsAny(context, timeKeywords) {
			return &GuessResult{Value: "10 الی 12", Reason: "Time"}
		}
		return &GuessResult{Value: af.fixedPhone, Reason: "Phone"}
	}
	if af.containsAny(context, configs.EmailKeywords) || fieldType == "email" {
		email := af.fixedEmail
		if email == "" {
			email = "user@example.com"
		}
		return &GuessResult{Value: email, Reason: "Email"}
	}
	if af.containsAny(context, configs.NameKeywords) {
		name := af.fixedName
		if name == "" {
			name = configs.PersianFirstNames[0]
		}
		return &GuessResult{Value: name, Reason: "Name"}
	}
	if af.containsAny(context, []string{"family", "lname", "فامیلی", "خانوادگی"}) {
		return &GuessResult{Value: configs.PersianLastNames[0], Reason: "Family"}
	}
	if af.containsAny(context, configs.MessageKeywords) || fieldType == "textarea" {
		subjectKeywords := []string{"subject", "onvan", "موضوع"}
		if af.containsAny(context, subjectKeywords) {
			return &GuessResult{Value: "درخواست مشاوره", Reason: "Subject"}
		}
		return &GuessResult{Value: "با سلام. درخواست مشاوره دارم. لطفا تماس بگیرید.", Reason: "Message"}
	}
	if af.mode == ModeRegister {
		if af.containsAny(context, []string{"password", "pass", "رمز", "کلمه عبور"}) {
			return &GuessResult{Value: "Test@1234", Reason: "Password"}
		}
	}
	if fieldType == "text" {
		if af.containsAny(context, []string{"age", "old", "year", "سن", "سال"}) {
			return &GuessResult{Value: "30", Reason: "Age"}
		}
	}
	return nil
}

func (af *AutoFormFiller) verifySubmission(html string, statusCode int) *VerifyResult {
	lowerHTML := strings.ToLower(html)
	for _, indicator := range configs.ErrorKeywords {
		if strings.Contains(lowerHTML, indicator) {
			return &VerifyResult{Status: false, Message: "ارسال شد اما خطای اعتبارسنجی دارد."}
		}
	}
	for _, keyword := range configs.SuccessKeywords {
		if strings.Contains(lowerHTML, keyword) {
			return &VerifyResult{Status: true, Message: "موفقیت آمیز."}
		}
	}
	return &VerifyResult{Status: true, Message: "فرم ارسال شد (تایید نشده اما ارور نداشت)."}
}

type VerifyResult struct {
	Status  bool
	Message string
}

func (af *AutoFormFiller) containsAny(haystack string, needles []string) bool {
	for _, needle := range needles {
		if strings.Contains(haystack, needle) {
			return true
		}
	}
	return false
}

func (af *AutoFormFiller) log(format string, args ...interface{}) {
	if af.debug {
		msg := fmt.Sprintf(format, args...)
		af.logs = append(af.logs, msg)
		fmt.Println(msg)
	}
}

func (af *AutoFormFiller) result(status bool, message string) *Result {
	af.log(message)
	return &Result{Status: status, Message: message, Logs: af.logs}
}

func (af *AutoFormFiller) truncate(s string, length int) string {
	runes := []rune(s)
	if len(runes) > length {
		return string(runes[:length])
	}
	return s
}

func (af *AutoFormFiller) isOTPScreen(html string) bool {
	lowerHTML := strings.ToLower(html)
	otpKeywords := []string{
		"کد تایید", "کد پیامک شده", "کد ارسال شده", "رمز یکبار مصرف",
		"کد اعتبارسنجی", "کد فعالسازی", "کد فعال سازی", "verification code",
		"enter code", "کد ۵ رقمی", "کد 4 رقمی", "کد ۶ رقمی", "ارسال مجدد",
	}

	for _, kw := range otpKeywords {
		if strings.Contains(lowerHTML, kw) {
			return true
		}
	}
	return false
}
