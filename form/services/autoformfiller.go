package services

import (
	"fmt"
	"strings"
	"sync"
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
		timeout:  30 * time.Second,
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

// متد اصلی رابط عمومی که به صورت Thread-Safe داده‌ها را به doSubmit می‌فرستد
func (af *AutoFormFiller) SubmitForm(targetURL, phoneNumber string, targetName ...string) *Result {
	name := af.fixedName
	if len(targetName) > 0 && targetName[0] != "" {
		name = targetName[0]
	}
	return af.doSubmit(targetURL, phoneNumber, name, af.fixedEmail, af.mode)
}

func (af *AutoFormFiller) Register(targetURL, phoneNumber, name, email string) *Result {
	return af.doSubmit(targetURL, phoneNumber, name, email, ModeRegister)
}

// هسته مرکزی پردازش فرم که کاملا مستقل و بدون تداخل با سایر تب‌ها عمل می‌کند
func (af *AutoFormFiller) doSubmit(targetURL, phone, name, email string, currentMode Mode) *Result {
	var localLogs []string
	var logMu sync.Mutex
	logFn := func(format string, args ...interface{}) {
		if af.debug {
			msg := fmt.Sprintf(format, args...)
			logMu.Lock()
			localLogs = append(localLogs, msg)
			logMu.Unlock()
			fmt.Println(msg)
		}
	}

	logFn("==========================================")
	logFn("URL: %s", targetURL)

	page, err := af.browser.Page(proto.TargetCreateTarget{URL: targetURL})
	if err != nil {
		msg := fmt.Sprintf("خطا در ایجاد صفحه: %v", err)
		logFn(msg)
		return &Result{Status: false, Message: msg, Logs: localLogs}
	}
	defer page.Close()

	page = page.Timeout(af.timeout)

	err = page.WaitLoad()
	if err != nil {
		logFn("اخطار: لود صفحه کامل نشد یا تایم‌اوت رخ داد، پردازش را ادامه می‌دهیم...")
	}
	_ = page.WaitStable(2 * time.Second)

	workingFrame := page
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
					logFn("تشخیص iframe فرم‌ساز: %s", info.URL)
					workingFrame = f
					break
				}
			}
			if workingFrame != page {
				break
			}
		}
	}

	// بررسی دکمه‌های دروازه‌ای فقط در حالت Register مجاز است
	if currentMode == ModeRegister {
		logFn("--- بررسی وجود دکمه‌های دروازه‌ای هدر (ورود / عضویت) ---")
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
					logFn("🎯 دکمه دروازه‌ای هدر پیدا شد، انجام کلیک فیزیکی...")
					_ = btn.ScrollIntoView()
					err = btn.Click(proto.InputMouseButtonLeft, 1)
					if err == nil {
						_ = workingFrame.WaitStable(1 * time.Second)
					}
				}
			}
		}
	} else {
		logFn("--- حالت FillForm: عبور از دکمه‌های هدر و پردازش مستقیم فرم صفحه ---")
	}

	logFn("--- شروع پردازش هوشمند سراسری صفحه و مودال‌ها ---")
	guessedData := make(map[string]string)

	for step := 1; step <= 3; step++ {
		logFn("مرحله پردازش: %d", step)
		_ = workingFrame.WaitStable(1 * time.Second)

		if htmlContent, err := workingFrame.HTML(); err == nil {
			if af.isOTPScreen(htmlContent) {
				logFn("✅ صفحه دریافت کد تایید (OTP) تشخیص داده شد. فرم با موفقیت ارسال شده است.")
				return &Result{
					Status:   true,
					Message:  "رسیدن به مرحله کد تایید (موفق)",
					Logs:     localLogs,
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
			logFn("خطا در واکشی عمیق اینپوت‌ها: %v", err)
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

			inputName := getJSString(input, `() => this.name || this.id || this.placeholder || ""`)
			if inputName == "" {
				inputName = fmt.Sprintf("global_step%d_field_%d", step, i)
			}

			if _, exists := guessedData[inputName]; exists {
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
			searchStr := strings.ToLower(fmt.Sprintf("%s %s %s", inputName, contextStr, placeholder))

			guess := af.guessValue(targetURL, inputName, searchStr, inputType, tagName, phone, name, email, currentMode, logFn)
			if guess != nil {
				guessedData[inputName] = guess.Value
				logFn("   >>> فیلد [%s] شناسایی و با موفقیت پر شد.", inputName)
				af.fillField(input, inputType, tagName, guess.Value)
				filledAny = true
			}
		}

		if !filledAny && step > 1 {
			logFn("میدان جدیدی پر نشد. پایان چرخه فرم.")
			break
		}

		logFn("تلاش برای پیدا کردن دکمه سابمیت فرم...")

		jsFormSubmitter := `function() {
			let btn = document.querySelector('button[type="submit"], input[type="submit"]');
			if (btn) {
				let style = window.getComputedStyle(btn);
				if (style.display !== 'none' && style.visibility !== 'hidden') {
					btn.scrollIntoView({ block: 'center' });
					btn.setAttribute('data-bot-submit', 'true');
					return "button_found";
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
							return "button_found";
						}
					}
				}
			}

			let form = document.querySelector('form');
			if (form) {
				form.setAttribute('data-bot-form', 'true');
				return "form_found";
			}
			return "";
		}`

		clicked := false
		if clickRes, err := workingFrame.Eval(jsFormSubmitter); err == nil && clickRes != nil {
			resType := clickRes.Value.Str()

			if resType == "button_found" {
				btn, err := workingFrame.Element("[data-bot-submit='true']")
				if err == nil {
					_ = btn.ScrollIntoView()
					err = btn.Click(proto.InputMouseButtonLeft, 1)
					if err == nil {
						_ = workingFrame.WaitStable(2 * time.Second)
						clicked = true
						filledAny = true
					}
				}
			} else if resType == "form_found" {
				logFn("دکمه مشخصی یافت نشد، تلاش برای ارسال مستقیم فرم (Submit Event)...")
				_, err := workingFrame.Eval(`() => { let f = document.querySelector("[data-bot-form='true']"); if(f) f.submit(); }`)
				if err == nil {
					_ = workingFrame.WaitStable(2 * time.Second)
					clicked = true
					filledAny = true
				}
			}
		}

		if clicked {
			if af.waitForOTP(workingFrame, 15*time.Second, logFn) {
				return &Result{
					Status:   true,
					Message:  "رسیدن به مرحله کد تایید (موفق)",
					Logs:     localLogs,
					SentData: guessedData,
				}
			}
		} else {
			logFn("هیچ دکمه سابمیتی برای خود فرم در این مرحله پیدا نشد.")
			if !filledAny {
				break
			}
		}
	}

	logFn("--- پایان پردازش فرم ---")

	content, err := page.HTML()
	if err != nil {
		return &Result{
			Status:   false,
			Message:  "خطا در خواندن محتوای صفحه در پایان کار",
			Logs:     localLogs,
			SentData: guessedData,
		}
	}

	verification := af.verifySubmission(content, 200)

	if af.isOTPScreen(content) {
		verification.Status = true
		verification.Message = "رسیدن به مرحله کد تایید (موفق)"
	}

	return &Result{
		Status:   verification.Status,
		Message:  verification.Message,
		Logs:     localLogs,
		SentData: guessedData,
	}
}

func (af *AutoFormFiller) waitForOTP(page *rod.Page, timeout time.Duration, logFn func(string, ...interface{})) bool {
	logFn("⏳ در حال انتظار تا حداکثر %s برای باز شدن صفحه OTP (کد تایید)...", timeout.String())
	timeoutPage := page.Timeout(timeout)
	for {
		time.Sleep(500 * time.Millisecond)
		html, err := timeoutPage.HTML()
		if err != nil {
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
			// اسکریپت تهاجمی‌تر برای انتخاب قطعی رادیوها و چک‌باکس‌ها در فرم‌سازها
			jsRadioCheckboxFix := `function(el) {
				if (!el) return;
				if (el.checked) return;

				// روش اول: کلیک بومی دام (بهترین روش برای بیدار کردن React/Vue)
				el.click();

				// روش دوم: اگر کلیک بومی جواب نداد، مقداردهی مستقیم
				el.checked = true;
				let nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, "checked")?.set;
				if (nativeSetter) {
					nativeSetter.call(el, true);
				}

				el.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
				el.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));

				// روش سوم: اگر اینپوت مخفی است، روی لیبلِ دربرگیرنده آن کلیک کن
				if (el.parentElement && el.parentElement.tagName.toLowerCase() === 'label') {
					el.parentElement.click();
				} else if (el.id) {
					let label = document.querySelector('label[for="' + el.id + '"]');
					if (label) label.click();
				}
			}`

			// اجرای اسکریپت جاوااسکریپتی به جای کلیک فیزیکی Rod که ممکن است روی عناصر مخفی کرش کند
			_, err := el.Eval(jsRadioCheckboxFix)
			if err != nil {
				// Fallback در صورت بروز هرگونه مشکل
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

func (af *AutoFormFiller) BatchSubmit(urls []string, phoneNumber string, targetName ...string) *BatchResult {
	start := time.Now()
	result := &BatchResult{
        Total:   len(urls),
        Results: make([]*Result, 0, len(urls)),
        Errors:  make([]string, 0),
    }

	for _, u := range urls {
		// اجرای مستقیم به جای استفاده از go func (پردازش دونه دونه)
		func(url string) {
			defer func() {
				if r := recover(); r != nil {
					af.log("خطای جدی در سایت %s رخ داد: %v", url, r)
					result.Failed++
					result.Errors = append(result.Errors, fmt.Sprintf("%s: خطای ناشناخته (Panic: %v)", url, r))
				}
			}()

			r := af.SubmitForm(url, phoneNumber, targetName...)

			result.Results = append(result.Results, r)
			if r.Status {
				result.Success++
			} else {
				result.Failed++
				result.Errors = append(result.Errors, fmt.Sprintf("%s: %s", url, r.Message))
			}
		}(u)
	}

	result.Duration = time.Since(start).String()
	return result
}

func (af *AutoFormFiller) BatchRegister(urls []string, phoneNumber, name, email string) *BatchResult {
	start := time.Now()
	result := &BatchResult{
        Total:   len(urls),
        Results: make([]*Result, 0, len(urls)),
        Errors:  make([]string, 0),
    }

	for _, u := range urls {
		// اجرای مستقیم به جای استفاده از go func (پردازش دونه دونه)
		func(url string) {
			defer func() {
				if r := recover(); r != nil {
					af.log("خطای جدی در سایت %s رخ داد: %v", url, r)
					result.Failed++
					result.Errors = append(result.Errors, fmt.Sprintf("%s: خطای ناشناخته (Panic: %v)", url, r))
				}
			}()

			r := af.Register(url, phoneNumber, name, email)

			result.Results = append(result.Results, r)
			if r.Status {
				result.Success++
			} else {
				result.Failed++
				result.Errors = append(result.Errors, fmt.Sprintf("%s: %s", url, r.Message))
			}
		}(u)
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

func (af *AutoFormFiller) guessValue(pageURL, name, context, fieldType, tagName, phone, personName, email string, currentMode Mode, logFn func(string, ...interface{})) *GuessResult {
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
		return &GuessResult{Value: phone, Reason: "Phone"}
	}
	if af.containsAny(context, configs.EmailKeywords) || fieldType == "email" {
		if email == "" {
			email = "abc@gmail.com"
		}
		return &GuessResult{Value: email, Reason: "Email"}
	}
	if af.containsAny(context, configs.NameKeywords) {
		if personName == "" && len(configs.PersianFirstNames) > 0 {
			personName = configs.PersianFirstNames[0]
		}
		return &GuessResult{Value: personName, Reason: "Name"}
	}
	if af.containsAny(context, []string{"family", "lname", "فامیلی", "خانوادگی"}) {
		if len(configs.PersianLastNames) > 0 {
			return &GuessResult{Value: configs.PersianLastNames[0], Reason: "Family"}
		}
		return &GuessResult{Value: name, Reason: "Family"}
	}
	if af.containsAny(context, configs.MessageKeywords) || fieldType == "textarea" {
		subjectKeywords := []string{"subject", "onvan", "موضوع"}
		if af.containsAny(context, subjectKeywords) {
			return &GuessResult{Value: "درخواست بررسی", Reason: "Subject"}
		}
		return &GuessResult{Value: "با سلام. فرم برای بررسی و ثبت ارسال شده است.", Reason: "Message"}
	}
	if currentMode == ModeRegister {
		if af.containsAny(context, []string{"password", "pass", "رمز", "کلمه عبور"}) {
			return &GuessResult{Value: "Test@1234", Reason: "Password"}
		}
	}
	if fieldType == "text" {
		if af.containsAny(context, []string{"age", "old", "year", "سن", "سال"}) {
			return &GuessResult{Value: "30", Reason: "Age"}
		}
	}

	// Fallback برای فرم‌های عمومی: مقداردهی اجباری به فیلدهای متنی ناشناخته
	if fieldType == "text" || fieldType == "textarea" || tagName == "textarea" {
		logFn("فیلد ناشناخته تشخیص داده شد: %s، استفاده از مقدار پیش‌فرض Fallback", name)
		return &GuessResult{Value: "لطفا تماس بگیرید", Reason: "Fallback_Text"}
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

// این متد صرفا برای پرینت خطاهای سطح سیستم (مانند پنیک‌ها) نگه‌داشته شده است
func (af *AutoFormFiller) log(format string, args ...interface{}) {
	if af.debug {
		fmt.Printf(format+"\n", args...)
	}
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
