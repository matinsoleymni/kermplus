package services

import (
	"fmt"
	"math/rand"
	"regexp"
	"strings"
	"time"

	"github.com/mxschmitt/playwright-go"
)

type ProcessResult struct {
	URL        string   `json:"url"`
	Success    bool     `json:"success"`
	Message    string   `json:"message"`
	Errors     []string `json:"errors,omitempty"`
	HTTPStatus int      `json:"http_status"`
}

type BatchResult struct {
	Total    int             `json:"total"`
	Success  int             `json:"success"`
	Failed   int             `json:"failed"`
	Duration string          `json:"duration"`
	Results  []ProcessResult `json:"results"`
	Errors   []string        `json:"errors,omitempty"`
}

type AutoFormFiller struct {
	pw    *playwright.Playwright
	debug bool
}

type Option func(*AutoFormFiller)

func WithDebug(debug bool) Option {
	return func(a *AutoFormFiller) {
		a.debug = debug
	}
}

func NewAutoFormFiller(opts ...Option) (*AutoFormFiller, error) {
	pw, err := playwright.Run()
	if err != nil {
		return nil, fmt.Errorf("خطا در راه‌اندازی Playwright Engine: %w", err)
	}

	filler := &AutoFormFiller{
		pw:    pw,
		debug: false,
	}

	for _, opt := range opts {
		opt(filler)
	}

	return filler, nil
}

func (a *AutoFormFiller) Close() {
	if a.pw != nil {
		a.pw.Stop()
	}
}

// -------------------------------------------------------------
// متدهای اصلی صدا زده شده توسط main.go
// -------------------------------------------------------------

func (a *AutoFormFiller) BatchSubmit(sites []string, phoneNumber, fullName string) *BatchResult {
	return a.runBatch(sites, phoneNumber, fullName, "", false)
}

func (a *AutoFormFiller) BatchRegister(sites []string, phoneNumber, fullName, email string) *BatchResult {
	return a.runBatch(sites, phoneNumber, fullName, email, true)
}

// -------------------------------------------------------------
// منطق اصلی اتوماسیون
// -------------------------------------------------------------

func (a *AutoFormFiller) runBatch(sites []string, phoneNumber, fullName, email string, shouldSubmit bool) *BatchResult {
	startTime := time.Now()
	result := &BatchResult{
		Total:   len(sites),
		Results: make([]ProcessResult, 0, len(sites)),
		Errors:  make([]string, 0),
	}

	headless := !a.debug

	browser, err := a.pw.Chromium.Launch(playwright.BrowserTypeLaunchOptions{
		Headless: playwright.Bool(headless),
		Args:     []string{"--disable-blink-features=AutomationControlled"},
	})
	if err != nil {
		result.Errors = append(result.Errors, fmt.Sprintf("خطا در اجرای مرورگر: %v", err))
		result.Duration = time.Since(startTime).String()
		return result
	}
	defer browser.Close()

	context, err := browser.NewContext(playwright.BrowserNewContextOptions{
		Viewport: &playwright.Size{Width: 1280, Height: 720},
	})
	if err != nil {
		result.Errors = append(result.Errors, fmt.Sprintf("خطا در ایجاد Context مرورگر: %v", err))
		result.Duration = time.Since(startTime).String()
		return result
	}
	defer context.Close()

	firstName, lastName := splitFullName(fullName)

	for _, targetURL := range sites {
		page, err := context.NewPage()
		if err != nil {
			res := ProcessResult{URL: targetURL, Success: false, Message: "خطا در ایجاد صفحه جدید"}
			result.Results = append(result.Results, res)
			result.Failed++
			result.Errors = append(result.Errors, fmt.Sprintf("[%s]: %s", targetURL, res.Message))
			continue
		}

		res := a.processSingleURL(page, targetURL, phoneNumber, firstName, lastName, email, shouldSubmit)
		page.Close()

		result.Results = append(result.Results, res)
		if res.Success {
			result.Success++
		} else {
			result.Failed++
			result.Errors = append(result.Errors, fmt.Sprintf("[%s]: %s", targetURL, res.Message))
		}
	}

	result.Duration = time.Since(startTime).String()
	return result
}

func (a *AutoFormFiller) processSingleURL(page playwright.Page, targetURL string, phone, firstName, lastName, email string, shouldSubmit bool) ProcessResult {
	_, err := page.Goto(targetURL, playwright.PageGotoOptions{
		Timeout:   playwright.Float(45000),
		WaitUntil: playwright.WaitUntilStateDomcontentloaded,
	})
	if err != nil {
		return ProcessResult{URL: targetURL, Success: false, Message: fmt.Sprintf("خطا در بارگذاری صفحه: %v", err)}
	}

	page.WaitForTimeout(2500)
	a.dismissAnnoyingModals(page)

	// 1. ورود هوشمند به فرم
	a.handleGatewayButtons(page)

	initialURL := page.URL()

	// 2. پر کردن هوشمند فیلدها
	a.fillInput(page, "input[type=\"tel\"], input[placeholder*=\"شماره\" i], input[placeholder*=\"موبایل\" i], input[name*=\"phone\" i], input[name*=\"mobile\" i]", phone)
	a.fillInput(page, "input[type=\"email\"], input[placeholder*=\"ایمیل\" i], input[name*=\"email\" i]", email)
	a.fillInput(page, "input[name*=\"first\" i], input[name=\"name\"], input[placeholder*=\"نام\" i]:not([placeholder*=\"خانوادگی\" i])", firstName)
	a.fillInput(page, "input[name*=\"last\" i], input[name*=\"family\" i], input[placeholder*=\"خانوادگی\" i], input[placeholder*=\"فامیلی\" i]", lastName)

	// پسوردها
	passwords := page.Locator("input[type=\"password\"]")
	pCount, _ := passwords.Count()
	for i := 0; i < pCount; i++ {
		if vis, _ := passwords.Nth(i).IsVisible(); vis {
			a.typeHumanLike(passwords.Nth(i), "Test@123456!AbC")
		}
	}

	// 3. انتخاب رندوم Selectها
	a.handleSelectDropdowns(page)

	// 4. تیک زدن قوانین
	a.handleTermsAndConditions(page)

	if !shouldSubmit {
		return ProcessResult{
			URL:     targetURL,
			Success: true,
			Message: "فیلدها، دراپ‌داون‌ها و قوانین با موفقیت پر شدند (بدون کلیک دکمه ارسال).",
		}
	}

	// --- عملیات ثبت‌نام ---
	var formNetworkStatus int
	page.OnResponse(func(res playwright.Response) {
		req := res.Request()
		method := req.Method()
		if method == "POST" || method == "PUT" {
			postData, _ := req.PostData()
			if strings.Contains(postData, phone) || (email != "" && strings.Contains(postData, email)) {
				formNetworkStatus = res.Status()
			}
		}
	})

	submitBtn := page.Locator("button, input[type=\"submit\"], [role=\"button\"]").
		Filter(playwright.LocatorFilterOptions{
			HasText: regexp.MustCompile("(?i)ثبت\\s*نام|ورود|عضویت|ارسال|ادامه|تایید|ثبت|submit|register|login|continue"),
		}).First()

	clicked := false
	if vis, _ := submitBtn.IsVisible(); vis {
		a.safeClick(page, submitBtn)
		clicked = true
	} else {
		form := page.Locator("form").First()
		if fVis, _ := form.IsVisible(); fVis {
			form.Evaluate("f => f.submit()", nil) // اصلاح شد
			clicked = true
		}
	}

	if !clicked {
		return ProcessResult{URL: targetURL, Success: false, Message: "هیچ دکمه‌ای برای ارسال فرم یافت نشد."}
	}

	page.WaitForTimeout(4000)

	// 5. بررسی ورود به مرحله OTP (کد پیامکی)
	otpLocator := page.Locator("input[autocomplete=\"one-time-code\"], input[placeholder*=\"کد\" i], input[name*=\"code\" i], input[name*=\"otp\" i], .otp__input")
	otpCount, _ := otpLocator.Count()

	if otpCount > 0 {
		return ProcessResult{
			URL:        targetURL,
			Success:    true,
			Message:    "موفقیت‌آمیز - فرم ارسال شد و صفحه وارد مرحله ورود کد پیامک گردید.",
			HTTPStatus: formNetworkStatus,
		}
	}

	visualErrors := a.checkVisualErrors(page)
	if len(visualErrors) > 0 {
		return ProcessResult{
			URL:        targetURL,
			Success:    false,
			Message:    "ارسال ناموفق (خطای اعتبارسنجی فرم)",
			Errors:     visualErrors,
			HTTPStatus: formNetworkStatus,
		}
	}

	if formNetworkStatus >= 400 {
		return ProcessResult{
			URL:        targetURL,
			Success:    false,
			Message:    fmt.Sprintf("سرور پاسخ خطای %d داد.", formNetworkStatus),
			HTTPStatus: formNetworkStatus,
		}
	}

	if page.URL() == initialURL && formNetworkStatus == 0 {
		return ProcessResult{
			URL:        targetURL,
			Success:    false,
			Message:    "دکمه کلیک شد اما هیچ واکنشی در شبکه یا صفحه رخ نداد.",
			HTTPStatus: 0,
		}
	}

	return ProcessResult{
		URL:        targetURL,
		Success:    true,
		Message:    "موفقیت‌آمیز (تایید شد)",
		HTTPStatus: formNetworkStatus,
	}
}

// -------------------------------------------------------------
// توابع اتوماسیون هوشمند (اصلاح‌شده)
// -------------------------------------------------------------

func (a *AutoFormFiller) fillInput(page playwright.Page, selector string, text string) {
	if text == "" {
		return
	}
	input := page.Locator(selector).First()
	if vis, _ := input.IsVisible(); vis {
		a.typeHumanLike(input, text)
	}
}

func (a *AutoFormFiller) typeHumanLike(locator playwright.Locator, text string) {
	locator.Focus()
	locator.Clear()
	locator.PressSequentially(text, playwright.LocatorPressSequentiallyOptions{Delay: playwright.Float(40)})

	locator.Evaluate(`el => {
		el.dispatchEvent(new Event('input', { bubbles: true }));
		el.dispatchEvent(new Event('change', { bubbles: true }));
	}`, nil) // اصلاح شد
	locator.Press("Tab")
}

func (a *AutoFormFiller) handleGatewayButtons(page playwright.Page) bool {
	inputs, _ := page.Locator("input:not([type=\"hidden\"]):not([type=\"checkbox\"])").Count()
	if inputs >= 2 {
		return true
	}

	hrefSelectors := []string{
		"a[href*=\"login\"]", "a[href*=\"register\"]", "a[href*=\"signup\"]",
		"a[href*=\"auth\"]", "a[href*=\"profile\"]", "a[href*=\"account\"]",
		"a[href*=\"consult\"]", "a[href*=\"user\"]",
	}

	for _, sel := range hrefSelectors {
		el := page.Locator(sel).First()
		if vis, _ := el.IsVisible(); vis {
			a.safeClick(page, el)
			page.WaitForTimeout(3000)
			return true
		}
	}

	gatewayRegex := regexp.MustCompile("(?i)ورود|ثبت\\s*‌?نام|عضویت|لاگین|حساب|پروفایل|مشاوره|رایگان|شروع\\s*‌?کنید|درخواست|ارتباط|login|register|sign\\s*‌?up|sign\\s*‌?in|account|profile|auth")
	candidates := page.Locator("header button, header a, nav button, nav a, button, a, [role=\"button\"], [class*=\"btn\"], [class*=\"login\"], [class*=\"register\"], [class*=\"auth\"], [class*=\"cta\"]")
	count, _ := candidates.Count()

	for i := 0; i < count; i++ {
		el := candidates.Nth(i)
		if vis, _ := el.IsVisible(); vis {
			innerText, _ := el.InnerText()
			ariaLabel, _ := el.GetAttribute("aria-label")
			text := strings.TrimSpace(innerText)
			if text == "" {
				text = strings.TrimSpace(ariaLabel)
			}
			text = regexp.MustCompile(`\s+`).ReplaceAllString(text, " ")

			if len(text) > 0 && len(text) < 40 && gatewayRegex.MatchString(text) {
				a.safeClick(page, el)
				page.WaitForTimeout(3000)
				return true
			}
		}
	}

	return false
}

func (a *AutoFormFiller) safeClick(page playwright.Page, locator playwright.Locator) {
	locator.ScrollIntoViewIfNeeded(playwright.LocatorScrollIntoViewIfNeededOptions{Timeout: playwright.Float(2000)})
	err := locator.Click(playwright.LocatorClickOptions{Force: playwright.Bool(true), Timeout: playwright.Float(3000)})
	if err != nil {
		locator.Evaluate(`node => {
			if (node instanceof HTMLElement) {
				node.click();
			} else if (node.parentElement) {
				node.parentElement.click();
			}
		}`, nil) // اصلاح شد
	}
}

func (a *AutoFormFiller) handleSelectDropdowns(page playwright.Page) {
	selects := page.Locator("select")
	count, _ := selects.Count()
	for i := 0; i < count; i++ {
		sel := selects.Nth(i)
		if vis, _ := sel.IsVisible(); vis {
			options, err := sel.Locator("option:not([disabled])").All()
			if err == nil && len(options) > 1 {
				randIdx := rand.Intn(len(options)-1) + 1
				sel.SelectOption(playwright.SelectOptionValues{
					Indexes: &[]int{randIdx}, // اصلاح شد
				})
			}
		}
	}

	customs := page.Locator("[role=\"combobox\"], .select__control, [class*=\"select-input\"]")
	cCount, _ := customs.Count()
	for i := 0; i < cCount; i++ {
		c := customs.Nth(i)
		if vis, _ := c.IsVisible(); vis {
			c.Click(playwright.LocatorClickOptions{Force: playwright.Bool(true)})
			page.WaitForTimeout(600)
			opts := page.Locator("[role=\"option\"], .select__option, li[class*=\"option\"]")
			if optCount, _ := opts.Count(); optCount > 0 {
				opts.First().Click(playwright.LocatorClickOptions{Force: playwright.Bool(true)})
			}
		}
	}
}

func (a *AutoFormFiller) handleTermsAndConditions(page playwright.Page) {
	checkboxes := page.Locator("input[type=\"checkbox\"], [role=\"checkbox\"]")
	count, _ := checkboxes.Count()
	if count > 0 {
		for i := 0; i < count; i++ {
			cb := checkboxes.Nth(i)
			err := cb.Check(playwright.LocatorCheckOptions{Force: playwright.Bool(true)})
			if err != nil {
				cb.Evaluate(`el => {
					el.checked = true;
					el.dispatchEvent(new Event('change', { bubbles: true }));
					el.dispatchEvent(new Event('click', { bubbles: true }));
				}`, nil) // اصلاح شد
			}
		}
	} else {
		labels := page.Locator("label").Filter(playwright.LocatorFilterOptions{
			HasText: regexp.MustCompile("(?i)قوانین|مقررات|شرایط"),
		})
		if lCount, _ := labels.Count(); lCount > 0 {
			labels.First().Evaluate(`el => {
				const clickTarget = el.querySelector('input, span, div') || el;
				clickTarget.click();
			}`, nil) // اصلاح شد
		}
	}
}

func (a *AutoFormFiller) dismissAnnoyingModals(page playwright.Page) {
	modals := page.GetByRole(*playwright.AriaRoleButton, playwright.PageGetByRoleOptions{ // اصلاح شد (*playwright.AriaRoleButton)
		Name: regexp.MustCompile("(?i)بستن|متوجه شدم|قبول|close|accept|dismiss|لغو"),
	})
	count, _ := modals.Count()
	for i := 0; i < count; i++ {
		if vis, _ := modals.Nth(i).IsVisible(); vis {
			modals.Nth(i).Click(playwright.LocatorClickOptions{Timeout: playwright.Float(1000), Force: playwright.Bool(true)})
		}
	}
}

func (a *AutoFormFiller) checkVisualErrors(page playwright.Page) []string {
	res, err := page.Evaluate(`() => {
		const errs = [];
		const inputs = document.querySelectorAll('input:not([type="hidden"]), textarea');
		inputs.forEach((el) => {
			const input = el;
			const style = window.getComputedStyle(input);
			const isRed = style.borderColor.includes('rgb(255') || style.color.includes('rgb(255');
			const hasErrorClass = input.className.match(/error|invalid|danger/i);
			if (isRed || hasErrorClass || !input.validity.valid) {
				const name = input.placeholder || input.name || input.id || "فیلد نامشخص";
				errs.push("[" + name + "]: خطای اعتبارسنجی فیلد");
			}
		});

		const alerts = document.querySelectorAll('.toast, .notification, [role="alert"], .Swal2-html-container, [class*="toast"], [class*="error"]:not(h1):not(h2):not(title)');
		alerts.forEach(alert => {
			const text = alert.textContent?.trim();
			if (text && text.length < 150 && !text.includes('تایید') && !text.includes('موفق') && !text.includes('الوپیک')) {
				errs.push("پیام سیستم: " + text);
			}
		});
		return [...new Set(errs)];
	}`, nil) // اصلاح شد

	if err != nil {
		return nil
	}

	var errs []string
	if list, ok := res.([]interface{}); ok {
		for _, item := range list {
			if str, ok := item.(string); ok {
				errs = append(errs, str)
			}
		}
	}
	return errs
}

func splitFullName(fullName string) (string, string) {
	parts := strings.SplitN(strings.TrimSpace(fullName), " ", 2)
	firstName := parts[0]
	lastName := firstName
	if len(parts) > 1 && strings.TrimSpace(parts[1]) != "" {
		lastName = strings.TrimSpace(parts[1])
	}
	return firstName, lastName
}
