<?php

namespace App\Services;

use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;
use Faker\Factory as Faker;
use Symfony\Component\DomCrawler\Crawler;
use App\Models\WhitelistedTarget;

class AutoFormFiller
{
    protected $client;
    protected $faker;
    protected $fixedPhone;
    protected $fixedName;
    protected $configs;
    protected $debug = false;
    protected $logs = [];

    public function __construct()
    {
        $this->client = new HttpBrowser(HttpClient::create([
            'verify_peer' => false,
            'verify_host' => false,
            'timeout' => (float) config('autofill.http_timeout', 60)
        ]));

        $this->faker = Faker::create('fa_IR');
    }

    public function setDebug(bool $status)
    {
        $this->debug = $status;
        return $this;
    }

    public function submitForm(string $url, string $phoneNumber, ?string $targetName = null)
    {
        $this->fixedPhone = $phoneNumber;
        $this->fixedName = $targetName;
        $this->logs = [];
        $this->log("==========================================");
        $this->log("URL: $url");

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($phoneNumber, WhitelistedTarget::TYPE_PHONE)) {
            return $this->result(false, $whitelist->getBlockMessage($phoneNumber, WhitelistedTarget::TYPE_PHONE));
        }

        try {
            $crawler = $this->client->request('GET', $url);

            // هندل کردن ریدایرکت‌ها و خطاهای شبکه
            if ($this->client->getInternalResponse()->getStatusCode() >= 400) {
                 return $this->result(false, "خطا در اتصال: " . $this->client->getInternalResponse()->getStatusCode());
            }

            // تلاش برای پیدا کردن iframe (برای سایت‌هایی مثل دیدار و فرم‌افزار)
            $iframe = $crawler->filter('iframe');
            if ($iframe->count() > 0) {
                $iframeSrc = $iframe->attr('src');
                if (str_contains($iframeSrc, 'form') || str_contains($iframeSrc, 'didar') || str_contains($iframeSrc, 'formafzar')) {
                    $this->log("تشخیص iframe فرم ساز: $iframeSrc");
                    // برو به آدرس iframe
                    $crawler = $this->client->request('GET', $iframeSrc);
                }
            }

            $formNode = $this->findBestFormNode($crawler);
            if (!$formNode) return $this->result(false, "فرمی پیدا نشد.");

            $form = $formNode->form();

            $this->log("--- پردازش فیلدها ---");

            $inputs = $formNode->filter('input, textarea, select');
            $guessedData = [];

            $inputs->each(function (Crawler $node) use (&$guessedData, $url, $crawler) {
                $name = $node->attr('name');
                $type = $node->attr('type') ?? $node->nodeName();

                if (!$name || in_array($type, ['submit', 'button', 'image', 'reset', 'file'])) return;

                // نادیده گرفتن فیلدهای سیستمی
                if (str_starts_with($name, 'gform_') || str_starts_with($name, 'is_submit_') || str_starts_with($name, '_wp')) return;

                // فیلدهای هیدن: فقط اگر مقدار دارند نگه دار، اگر خالی هستند دست نزن (ممکن است هانی‌پات باشند)
                if ($type == 'hidden') {
                    if (!empty($node->attr('value'))) return; // مقدار پیش‌فرض را نگه دار
                    // اگر خالی است، رها کن (معمولاً هانی‌پات نباید پر شود)
                    return;
                }

                $context = $this->findContextForNode($node, $crawler);
                $placeholder = $node->attr('placeholder') ?? '';
                $searchStr = strtolower("$name $context $placeholder");

                if ($this->debug) {
                    $short = mb_substr(trim(str_replace(["\n", "\r"], " ", $context)), 0, 40);
                    $this->log("F: [$name] ($type) | C: [$short]");
                }

                $guess = $this->guessValue($url, $name, $searchStr, $type, $node);

                if ($guess) {
                    $guessedData[$name] = $guess['value'];
                    $this->log("   >>> پر شد با: " . $this->truncate($guess['value']) . " (" . $guess['reason'] . ")");
                }
            });

            $this->log("--- ارسال ---");
            $this->client->submit($form, $guessedData);

            $responseContent = $this->client->getResponse()->getContent();
            $statusCode = $this->client->getInternalResponse()->getStatusCode();

            $verification = $this->verifySubmission($responseContent, $statusCode);

            return [
                'status' => $verification['success'],
                'message' => $verification['message'],
                'logs' => $this->logs,
                'sent_data' => $guessedData,
            ];

        } catch (\Exception $e) {
            // لاگ کردن خطای دقیق برای دیباگ بهتر
            return $this->result(false, "Error: " . $e->getMessage());
        }
    }

    private function guessValue($url, $name, $context, $type, Crawler $node)
    {
        $host = str_replace('www.', '', parse_url($url, PHP_URL_HOST));

        if (isset($this->configs[$host][$name])) {
            return ['value' => $this->generateFixedData($this->configs[$host][$name]), 'reason' => 'Config'];
        }

        // 1. هندل کردن دقیق Select Box (رفع باگ #21)
        if ($node->nodeName() == 'select') {
             try {
                 // گرفتن تمام آپشن‌ها
                 $options = $node->filter('option');
                 // تلاش برای انتخاب گزینه دوم (چون اولی معمولا "انتخاب کنید" است و value="" دارد)
                 foreach ($options as $index => $option) {
                     $val = $option->getAttribute('value');
                     // اگر ولیو داشت و خالی نبود انتخابش کن (اولی رو رد کن اگه خالی بود)
                     if (!empty($val) && $index > 0) {
                         return ['value' => $val, 'reason' => 'Select Option Valid'];
                     }
                 }
                 // اگر هیچی پیدا نشد، همون اولی رو بردار (فالبک)
                 if ($options->count() > 0) {
                     return ['value' => $options->first()->getAttribute('value'), 'reason' => 'Select Option First'];
                 }
             } catch(\Exception $e){}
             return null;
        }

        // 2. هندل کردن Radio
        if ($type == 'radio') {
            // فقط مقدار خود این نود را برگردان
            return ['value' => $node->attr('value'), 'reason' => 'Radio Value'];
        }

        // 3. هندل کردن Checkbox
        if ($type == 'checkbox') {
             if ($this->contains($context, ['rule', 'term', 'qavanin', 'شرایط', 'قوانین'])) return ['value' => true, 'reason' => 'Rules'];
             if (rand(0,1)) return ['value' => true, 'reason' => 'Random Check'];
             return null;
        }

        // 4. تشخیص فیلدهای متنی
        $phones = ['phone', 'mobile', 'tel', 'cell', 'shomare', 'tamams', 'تلفن', 'موبایل', 'تماس', 'همراه', 'call'];

        if ($this->contains($context, $phones)) {
            if ($this->contains($context, ['time', 'date', 'زمان', 'ساعت', 'محدوده', 'کی'])) {
                 return ['value' => '10 الی 12', 'reason' => 'Time']; // برای فیلد زمان تماس
            }
            return ['value' => $this->fixedPhone, 'reason' => 'Phone'];
        }

        if ($this->contains($context, ['email', 'mail', 'پست', 'ایمیل']) || $type == 'email') {
            return ['value' => $this->faker->email, 'reason' => 'Email'];
        }

        if ($this->contains($context, ['name', 'family', 'fname', 'lname', 'نام', 'فامیلی', 'خانوادگی', 'هویت'])) {
            $name = $this->fixedName ?: $this->faker->name;
            return ['value' => $name, 'reason' => 'Name'];
        }

        if ($this->contains($context, ['message', 'body', 'desc', 'comment', 'payam', 'tozih', 'پیام', 'توضیح', 'متن', 'subject', 'موضوع']) || $type == 'textarea') {
             if ($this->contains($context, ['subject', 'onvan', 'موضوع'])) return ['value' => 'درخواست مشاوره', 'reason' => 'Subject'];
             return ['value' => "با سلام. درخواست مشاوره دارم. لطفا تماس بگیرید.", 'reason' => 'Message'];
        }

        if ($this->contains($context, ['web', 'site', 'url', 'سایت', 'وب'])) {
            return ['value' => 'https://google.com', 'reason' => 'Website'];
        }

        // 5. هندل کردن فیلدهای عددی (رفع باگ #9)
        if ($type == 'number') {
            return ['value' => rand(1, 50), 'reason' => 'Number'];
        }

        // فالبک برای فیلدهای متنی ناشناخته
        if ($type == 'text') {
             if ($this->contains($context, ['age', 'old', 'year', 'سن', 'سال'])) return ['value' => '30', 'reason' => 'Age'];
             if ($this->contains($context, ['cod', 'melli', 'meli', 'id', 'کد', 'ملی'])) return ['value' => '0011223344', 'reason' => 'National ID'];
             return ['value' => 'بررسی شود', 'reason' => 'Fallback'];
        }

        return null;
    }

    // --- Helpers ---

    private function findContextForNode(Crawler $node, Crawler $rootCrawler): string
    {
        $text = "";
        if ($id = $node->attr('id')) {
            try {
                $label = $rootCrawler->filter("label[for='$id']");
                if ($label->count()) $text .= $label->text() . " ";
            } catch(\Exception $e){}
        }
        try {
            $gfield = $node->closest('.gfield');
            if ($gfield) {
                $gfLabel = $gfield->filter('.gfield_label');
                if ($gfLabel->count()) $text .= $gfLabel->text() . " ";
                else $text .= $gfield->text() . " ";
            } else {
                $parent = $node->closest('div.form-group') ?? $node->closest('label') ?? $node->closest('p');
                if ($parent) $text .= $parent->text() . " ";
            }
        } catch(\Exception $e) {}
        $text .= ($node->attr('placeholder') ?? "") . " ";
        return $text;
    }

    private function findBestFormNode(Crawler $crawler)
    {
        $forms = $crawler->filter('form');
        $best = null; $maxScore = -50;
        $forms->each(function (Crawler $node) use (&$best, &$maxScore) {
            $html = strtolower($node->html());
            $score = 0;
            if ($this->contains($html, ['phone', 'mobile', 'تلفن', 'تماس', 'همراه'])) $score += 50;
            if ($this->contains($html, ['name', 'نام'])) $score += 20;
            if (str_contains($node->attr('action') ?? '', 'search')) $score -= 100;
            if ($score > $maxScore) { $maxScore = $score; $best = $node; }
        });
        return $best;
    }

    private function verifySubmission($html, $statusCode)
    {
        if (str_contains($html, 'gfield_error') || str_contains($html, 'validation_error')) {
            return ['success' => false, 'message' => 'ارسال شد اما خطای اعتبارسنجی دارد.'];
        }
        $successKeywords = ['gform_confirmation_message', 'با تشکر', 'موفقیت', 'sent', 'successfully', 'دریافت شد', 'ثبت شد', 'ممنون'];
        foreach ($successKeywords as $k) if (str_contains($html, $k)) return ['success' => true, 'message' => 'موفقیت آمیز.'];
        if ($statusCode >= 200 && $statusCode < 400) return ['success' => true, 'message' => 'ارسال شد (Status 200).'];
        return ['success' => false, 'message' => "خطا: $statusCode"];
    }

    private function contains($haystack, $needles) { foreach ($needles as $n) if (str_contains($haystack, $n)) return true; return false; }
    private function log($msg) { if ($this->debug) $this->logs[] = $msg; }
    private function result($status, $msg) { $this->log($msg); return ['status' => $status, 'message' => $msg, 'logs' => $this->logs]; }
    private function truncate($s) { return mb_substr($s ?? '', 0, 20); }
    private function generateFixedData($type) { return match($type) { 'PHONE' => $this->fixedPhone, default => 'test' }; }
}
