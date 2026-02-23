<?php

namespace App\Telegram\Conversations;

use App\Helpers\AdminStatsHelper;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Telegram\Conversations\AdminSubscriptionConversation;
use App\Telegram\Conversations\AdminPlanConversation;
use App\Telegram\Conversations\AdminsManagementConversation;
use App\Telegram\Conversations\SponsorChannelsConversation;
use App\Telegram\Conversations\BroadcastConversation;
use App\Telegram\Conversations\AutoFormConversation;
use App\Telegram\Conversations\ReactionManagerConversation;
use App\Telegram\Handlers\MainMenuHandler;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class AdminPanelConversation extends Conversation
{
    public function start(Nutgram $bot)
    {
        // verify local admin mapping via telegram id
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) {
            $bot->sendMessage('❌ خطا در دریافت اطلاعات کاربر.');
            $this->end();
            return;
        }
        $local = \App\Models\User::where('telegram_id', $tgUser->id)->first();
        if (!$local || !$local->isAdmin()) {
            $bot->sendMessage('⛔️ شما دسترسی ادمین ندارید.');
            $this->end();
            return;
        }

        $snapshot = AdminStatsHelper::dashboardSnapshot();
        $adminText = $this->buildDashboardMessage($snapshot);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('💰 آمار درآمد/اشتراک', callback_data: 'admin_revenue', style: 'danger'))
            ->addRow(InlineKeyboardButton::make('🔎 جستجوی کاربر', callback_data: 'admin_manage_subscriptions', style: 'danger'))
            ->addRow(InlineKeyboardButton::make('💳 مدیریت پلن‌ها', callback_data: 'admin_manage_plans', style: 'danger'))
            ->addRow(InlineKeyboardButton::make('🧑‍💼 مدیریت ادمین‌ها', callback_data: 'admin_manage_admins', style: 'danger'))
            ->addRow(InlineKeyboardButton::make('📣 پیام همگانی', callback_data: 'admin_broadcast', style: 'danger'))
            // ->addRow(InlineKeyboardButton::make('📝 فرم‌ها', callback_data: 'admin_forms', style: 'danger'))
            // ->addRow(InlineKeyboardButton::make('💬 ری‌اکشن‌ها', callback_data: 'admin_reactions', style: 'danger'))
            ->addRow(InlineKeyboardButton::make('📑 مدیریت اسپانسر', callback_data: 'admin_sponsor_manage', style: 'danger'))
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'main_menu', style: 'danger', icon: '5352759161945867747'));
        $bot->sendMessage($adminText, reply_markup: $keyboard);
        $this->next('handleMenu');
    }

    public function handleMenu(Nutgram $bot)
    {
        $data = $bot->callbackQuery()?->data;
        switch ($data) {
            case 'admin_user_stats':
                $total = AdminStatsHelper::totalUsers();
                $daily = AdminStatsHelper::dailyActiveUsers();
                $weekly = AdminStatsHelper::weeklyActiveUsers();
                $monthly = AdminStatsHelper::monthlyActiveUsers();
                $msg = "👥 آمار کاربران:\n";
                $msg .= "👤 کل کاربران: {$total}\n";
                $msg .= "🟢 فعال روزانه: {$daily}\n";
                $msg .= "🔵 فعال هفتگی: {$weekly}\n";
                $msg .= "🟣 فعال ماهانه: {$monthly}";
                $bot->sendMessage($msg);
                break;
            case 'admin_broadcast':
                BroadcastConversation::begin($bot);
                $this->end();
                return;
            case 'admin_forms':
                AutoFormConversation::begin($bot);
                $this->end();
                return;
            case 'admin_reactions':
                ReactionManagerConversation::begin($bot);
                $this->end();
                return;
            case 'admin_manage_admins':
                AdminsManagementConversation::begin($bot);
                $this->end();
                return;
            case 'admin_manage_plans':
                AdminPlanConversation::begin($bot);
                $this->end();
                return;
            case 'admin_manage_subscriptions':
                AdminSubscriptionConversation::begin($bot);
                $this->end();
                return;
            case 'admin_sponsor_manage':
            case 'admin_sponsor_list': // backward compatibility
            case 'admin_sponsor_add': // backward compatibility
            case 'admin_sponsor_remove': // backward compatibility
                SponsorChannelsConversation::begin($bot);
                $this->end();
                return;
            case 'admin_revenue':
                $msg = \App\Helpers\SubscriptionHelper::getRevenueStatsMessage();
                $bot->sendMessage($msg);
                return;
            case 'admin_autofiller':
                $this->showAutofillerMenu($bot);
                return;

            case 'main_menu':
                (new MainMenuHandler())($bot);
                $this->end();
                return;
            default:
                $bot->sendMessage('❌ گزینه نامعتبر.');
        }
        $this->start($bot);
    }

    private function buildDashboardMessage(array $snapshot): string
    {
        $users = $snapshot['users'];
        $admins = $snapshot['admins'];
        $premium = $snapshot['premium_breakdown'];
        $now = $snapshot['generated_at'];

        $date = $this->formatPersianDate($now);
        $time = $now->format('H:i:s');

        $msg = "• 🤖 Bot Statistics ᴋᴇʀᴍᴘʟᴜꜱ 🍷 •\n\n";
        $msg .= "┬ 👥 Total Members : {$users['total']} users\n";
        $msg .= "┤ 👀 Active Users : {$users['active']} users\n";
        $msg .= "┘ 🕶️ Premium Members : {$users['premium']} users\n\n";

        $msg .= "┬ 🌤 Last 24 Hours : {$users['active_day']} users\n";
        $msg .= "┤ 7️⃣ Last Week : {$users['active_week']} users\n";
        $msg .= "┘ 🌙 Last Month : {$users['active_month']} users\n\n";

        $msg .= "┬ 👨‍💻 Admins Count : {$admins['count']}\n";
        if (empty($admins['names'])) {
            $msg .= "┤ 👨‍⚖️ -\n";
        } else {
            foreach ($admins['names'] as $name) {
                $msg .= "┤ 👨‍⚖️ {$name}\n";
            }
        }
        $msg .= "\n";

        $msg .= "┬ 📋 Paid Premiums : {$premium['paid']}\n";
        $msg .= "┤ 🎫 Referral Premiums : {$premium['referral']}\n";
        $msg .= "┤ 🤝 Manual Premiums : {$premium['manual']}\n\n";

        $msg .= "📆 {$date} - ⏰ {$time}";

        return $msg;
    }

    private function formatPersianDate(\Carbon\CarbonInterface $date): string
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return $date->format('Y/m/d');
        }

        $formatter = new \IntlDateFormatter(
            'en_US@calendar=persian',
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            $date->getTimezone()->getName(),
            \IntlDateFormatter::TRADITIONAL,
            'yyyy/MM/d'
        );

        if ($formatter === false) {
            return $date->format('Y/m/d');
        }

        $formatted = $formatter->format($date);

        return is_string($formatted) && $formatted !== ''
            ? $formatted
            : $date->format('Y/m/d');
    }

    private function showAutofillerMenu(Nutgram $bot)
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('▶️ Run All Sites', callback_data: 'autofiller_run_all', style: 'danger'),
                InlineKeyboardButton::make('🔗 Run Single URL', callback_data: 'autofiller_single_start', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'back_admin', style: 'danger', icon: '5352759161945867747')
            );
        $bot->sendMessage('🤖 **AutoFiller Control Panel**\n\nانتخاب کنید:', reply_markup: $keyboard);
        $this->next('processAutofillerAction');
    }

    public function processAutofillerAction(Nutgram $bot)
    {
        $data = $bot->callbackQuery()?->data;
        if ($data === 'back_admin') {
            AdminPanelConversation::begin($bot);
            $this->end();
            return;
        }
        if ($data === 'autofiller_run_all') {
            $bot->sendMessage('⏳ درحال اجرای AutoFiller برای تمام سایت‌ها...');
            \Illuminate\Support\Facades\Artisan::call('autofiller:run');
            $bot->sendMessage('✅ AutoFiller تکمیل شد! نتایج را در storage/logs/autofill-*.log ببینید.');
            $this->end();
            return;
        }
        if ($data === 'autofiller_single_start') {
            $bot->sendMessage('لطفا URL سایت را ارسال کنید:');
            $this->next('awaitSingleUrl');
            return;
        }
    }

    public function awaitSingleUrl(Nutgram $bot)
    {
        $url = $bot->message()?->text;
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            $bot->sendMessage('⛔️ URL نامعتبر است. لطفا دوباره تلاش کنید.');
            return;
        }
        $bot->sendMessage('⏳ درحال اجرا برای: ' . $url);
        \Illuminate\Support\Facades\Artisan::call('autofiller:run', ['--single' => $url]);
        $bot->sendMessage('✅ تکمیل شد!');
        $this->end();
    }
}
