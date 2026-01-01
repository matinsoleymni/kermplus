<?php

namespace App\Telegram\Conversations;

use App\Helpers\AdminStatsHelper;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use Illuminate\Support\Facades\DB;
use App\Telegram\Conversations\AdminSubscriptionConversation;
use App\Telegram\Conversations\AdminPlanConversation;
use App\Telegram\Conversations\AdminsManagementConversation;
use App\Telegram\Conversations\AssignPlanConversation;
use App\Telegram\Conversations\SponsorChannelsConversation;
use App\Telegram\Conversations\SuspendUserConversation;
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
            ->addRow(InlineKeyboardButton::make('📨 صف درخواست‌ها', callback_data: 'admin_requests'))
            ->addRow(InlineKeyboardButton::make('💰 آمار درآمد/اشتراک', callback_data: 'admin_revenue'))
            ->addRow(InlineKeyboardButton::make('🧾 مدیریت اشتراک‌ها', callback_data: 'admin_manage_subscriptions'))
            ->addRow(InlineKeyboardButton::make('💳 مدیریت پلن‌ها', callback_data: 'admin_manage_plans'))
            ->addRow(InlineKeyboardButton::make('🎫 افزودن/حذف پلن کاربر', callback_data: 'admin_assign_plan'))
            ->addRow(InlineKeyboardButton::make('🧑‍💼 مدیریت ادمین‌ها', callback_data: 'admin_manage_admins'))
            ->addRow(InlineKeyboardButton::make('📣 پیام همگانی', callback_data: 'admin_broadcast'))
            // ->addRow(InlineKeyboardButton::make('📝 فرم‌ها', callback_data: 'admin_forms'))
            // ->addRow(InlineKeyboardButton::make('💬 ری‌اکشن‌ها', callback_data: 'admin_reactions'))
            ->addRow(InlineKeyboardButton::make('📑 لیست اسپانسرها', callback_data: 'admin_sponsor_list'))
            ->addRow(InlineKeyboardButton::make('➕ افزودن اسپانسر', callback_data: 'admin_sponsor_add'))
            ->addRow(InlineKeyboardButton::make('➖ حذف اسپانسر', callback_data: 'admin_sponsor_remove'))
            ->addRow(InlineKeyboardButton::make('🚫 بن کردن کاربر', callback_data: 'admin_suspend_user'))
            ->addRow(InlineKeyboardButton::make('✅ رفع بن سریع', callback_data: 'admin_unsuspend_quick'))
            ->addRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'main_menu'));
        $bot->sendMessage($adminText, reply_markup: $keyboard, parse_mode: 'HTML');
        $this->next('handleMenu');
    }

    public function handleMenu(Nutgram $bot)
    {
        $data = $bot->callbackQuery()?->data;
        switch ($data) {
            case 'admin_requests':
                $jobs = DB::table('jobs')->orderByDesc('id')->limit(10)->get();
                if ($jobs->isEmpty()) {
                    $bot->sendMessage('⛔️ درخواستی ثبت نشده است.');
                } else {
                    $msg = "📝 آخرین درخواست‌ها:\n";
                    foreach ($jobs as $job) {
                        $payload = json_decode($job->payload, true);
                        $type = '';
                        $target = '';
                        if (isset($payload['displayName']) && $payload['displayName'] === 'SendSmsBombJob') {
                            $type = '💣 SMS';
                            $target = $payload['data']['phone'] ?? '';
                        } elseif (isset($payload['displayName']) && $payload['displayName'] === 'SendEmailBombJob') {
                            $type = '📧 Email';
                            $target = $payload['data']['email'] ?? '';
                        } else {
                            continue;
                        }
                        $msg .= "{$type} → {$target}\n";
                    }
                    $bot->sendMessage($msg);
                }
                break;
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
                $bot->sendMessage('📢 به بخش پیام همگانی خوش آمدید!');
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
            case 'admin_assign_plan':
                AssignPlanConversation::begin($bot);
                $this->end();
                return;
            case 'admin_sponsor_list':
            case 'admin_sponsor_add':
            case 'admin_sponsor_remove':
                SponsorChannelsConversation::begin($bot);
                $this->end();
                return;
            case 'admin_suspend_user':
                SuspendUserConversation::begin($bot, null, null, ['suspend']);
                $this->end();
                return;
            case 'admin_unsuspend_quick':
                SuspendUserConversation::begin($bot, null, null, ['unsuspend']);
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

        $adminNames = $admins['names'];
        $adminLines = empty($adminNames)
            ? '—'
            : implode("\n", array_map(fn($n, $i) => ($i + 1) . '. ' . $n, $adminNames, array_keys($adminNames)));

        $msg = "🤖 <b>Bot Statistics</b> — KermPlus 🧿🥃\n";
        $msg .= "\n";
        $msg .= "🔗 <b>Total Members</b> : <b>{$users['total']}</b> users\n";
        $msg .= "🟢 <b>Active Users</b> : <b>{$users['active']}</b> users\n";
        $msg .= "🖤 <b>Premium Members</b> : <b>{$users['premium']}</b> users\n";
        $msg .= "\n";
        $msg .= "⌚ <b>Last 24 Hours</b> : {$users['active_day']} users\n";
        $msg .= "📅 <b>Last Week</b> : {$users['active_week']} users\n";
        $msg .= "📆 <b>Last Month</b> : {$users['active_month']} users\n";
        $msg .= "\n";
        $msg .= "🛡 <b>Admins Count</b> : {$admins['count']}\n";
        $msg .= $adminLines . "\n";
        $msg .= "\n";
        $msg .= "💳 <b>Paid Premiums</b> : {$premium['paid']}\n";
        $msg .= "🎗 <b>Referral Premiums</b> : {$premium['referral']}\n";
        $msg .= "🔥 <b>Manual Premiums</b> : {$premium['manual']}\n";
        $msg .= "\n";
        $msg .= "🗓 <b>" . $now->format('Y/m/d') . "</b> • ⏱ " . $now->format('H:i:s');

        return $msg;
    }

    private function showAutofillerMenu(Nutgram $bot)
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('▶️ Run All Sites', callback_data: 'autofiller_run_all'),
                InlineKeyboardButton::make('🔗 Run Single URL', callback_data: 'autofiller_single_start')
            )
            ->addRow(
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'back_admin')
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
