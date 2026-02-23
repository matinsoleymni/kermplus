<?php

/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Telegram\Commands\AdminCommand;
use App\Telegram\Commands\StartCommand;
use App\Telegram\Conversations\AdminPanelConversation;
use App\Telegram\Conversations\BroadcastConversation;
use App\Telegram\Conversations\ChannelReactionConversation;
use App\Telegram\Conversations\AdminSubscriptionConversation;
use App\Telegram\Conversations\AdminPlanConversation;
use App\Telegram\Conversations\AdminsManagementConversation;
use App\Telegram\Conversations\AutoFormConversation;
use App\Telegram\Conversations\ReactionManagerConversation;
use App\Telegram\Conversations\SponsorChannelsConversation;
use App\Telegram\Conversations\EmailBombConversation;
use App\Telegram\Conversations\FormFillerConversation;
use App\Telegram\Conversations\InstagramReporterConversation;
use App\Telegram\Conversations\RubikaReporterConversation;
use App\Telegram\Conversations\SmsBombConversation;
use App\Telegram\Conversations\SmsBomberMenuConversation;
use App\Telegram\Conversations\TelegramReporterConversation;
use App\Telegram\Conversations\UserAutoFillerConversation;
use App\Telegram\Conversations\WhitelistConversation;
use App\Telegram\Handlers\Admin\AdminExitHandler;
use App\Telegram\Handlers\BuySubscriptionHandler;
use App\Telegram\Handlers\MainMenuHandler;
use App\Telegram\Handlers\SponsorJoinCheckHandler;
use App\Telegram\Handlers\PaymentPreCheckoutHandler;
use App\Telegram\Handlers\ReferralHandler;
use App\Telegram\Handlers\ReporterMenuHandler;
use App\Telegram\Handlers\ReporterWhatIsHandler;
use App\Telegram\Handlers\TelegramReporterMenuHandler;
use App\Telegram\Handlers\InstagramReporterMenuHandler;
use App\Telegram\Handlers\RubikaReporterMenuHandler;
use App\Telegram\Handlers\KermRiziHandler;
use App\Telegram\Handlers\MobileKermRiziHandler;
use App\Telegram\Handlers\PlusInfoHandler;
use App\Telegram\Handlers\BomberMenuHandler;
use App\Telegram\Handlers\NotImplementedHandler;
use App\Telegram\Handlers\SelectPlanHandler;
use App\Telegram\Handlers\SubscriptionInfoHandler;
use App\Telegram\Handlers\SupportInfoHandler;
use App\Telegram\Handlers\UserFormsHandler;
use App\Telegram\Handlers\UserProfileHandler;
use App\Telegram\Handlers\UserStatsHandler;
use App\Telegram\Middleware\EnsureSponsorJoinMiddleware;
use SergiX44\Nutgram\Nutgram;

/*
|--------------------------------------------------------------------------
| Nutgram Handlers
|--------------------------------------------------------------------------
|
| Here is where you can register telegram handlers for Nutgram. These
| handlers are loaded by the NutgramServiceProvider. Enjoy!
|
*/

$bot->middleware(EnsureSponsorJoinMiddleware::class);

$bot->onCommand('start', StartCommand::class);
$bot->onText('/start {code}', StartCommand::class);
$bot->onCommand('admin', AdminCommand::class);
$bot->onCallbackQueryData('admin_panel', AdminPanelConversation::class);
$bot->onCallbackQueryData('admin_manage_subscriptions', AdminSubscriptionConversation::class);
$bot->onCallbackQueryData('admin_manage_plans', AdminPlanConversation::class);
$bot->onCallbackQueryData('admin_manage_admins', AdminsManagementConversation::class);
$bot->onCallbackQueryData('admin_forms', AutoFormConversation::class);
$bot->onCallbackQueryData('admin_reactions', ReactionManagerConversation::class);
$bot->onCallbackQueryData('admin_sponsor_manage', SponsorChannelsConversation::class);
$bot->onCallbackQueryData('admin_sponsor_list', SponsorChannelsConversation::class);
$bot->onCallbackQueryData('admin_sponsor_add', SponsorChannelsConversation::class);
$bot->onCallbackQueryData('admin_sponsor_remove', SponsorChannelsConversation::class);
$bot->onCallbackQueryData('admin_autofiller', AdminPanelConversation::class);
$bot->onCallbackQueryData('back_admin', AdminPanelConversation::class);

$bot->onCallbackQueryData('user_smsbomb', SmsBombConversation::class);
$bot->onCallbackQueryData('user_emailbomb', EmailBombConversation::class);
$bot->onCallbackQueryData('admin_broadcast', BroadcastConversation::class);
$bot->onCallbackQueryData('user_autofiller', UserAutoFillerConversation::class);
$bot->onCallbackQueryData('whitelist_add', WhitelistConversation::class);
$bot->onCallbackQueryData('whitelist_menu', WhitelistConversation::class);
$bot->onCallbackQueryData('whitelist_edit', WhitelistConversation::class);
$bot->onCallbackQueryData('reporter_telegram', TelegramReporterConversation::class);
$bot->onCallbackQueryData('reporter_instagram', InstagramReporterConversation::class);
$bot->onCallbackQueryData('reporter_rubika_menu', RubikaReporterMenuHandler::class);
$bot->onCallbackQueryData('reporter_rubika', RubikaReporterMenuHandler::class);
$bot->onCallbackQueryData('fill_form_', FormFillerConversation::class);

$bot->onCallbackQueryData('subscription_info', SubscriptionInfoHandler::class);
$bot->onCallbackQueryData('support_info', SupportInfoHandler::class);
$bot->onCallbackQueryData('user_forms', UserFormsHandler::class);
$bot->onCallbackQueryData('reporter_menu', ReporterMenuHandler::class);
$bot->onCallbackQueryData('reporter_what_is', ReporterWhatIsHandler::class);
$bot->onCallbackQueryData('reporter_telegram_menu', TelegramReporterMenuHandler::class);
$bot->onCallbackQueryData('reporter_instagram_menu', InstagramReporterMenuHandler::class);
$bot->onCallbackQueryData('rubika_report_account', RubikaReporterConversation::class);
$bot->onCallbackQueryData('rubika_report_channel', RubikaReporterConversation::class);
$bot->onCallbackQueryData('rubika_report_group', RubikaReporterConversation::class);
$bot->onCallbackQueryData('telegram_report_account', TelegramReporterConversation::class);
$bot->onCallbackQueryData('telegram_report_channel', TelegramReporterConversation::class);
$bot->onCallbackQueryData('telegram_report_post', TelegramReporterConversation::class);
$bot->onCallbackQueryData('plus_info', PlusInfoHandler::class);
$bot->onCallbackQueryData('pro_info', PlusInfoHandler::class);
$bot->onCallbackQueryData('plan_diff_info', PlusInfoHandler::class);
$bot->onCallbackQueryData('instagram_report_page', InstagramReporterConversation::class);
$bot->onCallbackQueryData('instagram_report_post', InstagramReporterConversation::class);
$bot->onCallbackQueryData('kerm_menu', KermRiziHandler::class);
$bot->onCallbackQueryData('channel_reaction', ChannelReactionConversation::class);
$bot->onCallbackQueryData('mobile_kerm_menu', MobileKermRiziHandler::class);
$bot->onCallbackQueryData('bomber_menu', BomberMenuHandler::class);
$bot->onCallbackQueryData('bomber_free_sms', SmsBombConversation::class);
$bot->onCallbackQueryData('bomber_plus_sms', SmsBomberMenuConversation::class);
$bot->onCallbackQueryData('bomber_plus_call', NotImplementedHandler::class);
$bot->onCallbackQueryData('bomber_combo_plus', NotImplementedHandler::class);
$bot->onCallbackQueryData('not_implemented', NotImplementedHandler::class);
$bot->onCallbackQueryData('user_profile', UserProfileHandler::class);
$bot->onCallbackQueryData('buy_subscription', BuySubscriptionHandler::class);
$bot->onCallbackQueryData('buy_sub_crypto', BuySubscriptionHandler::class);
$bot->onCallbackQueryData('buy_sub_star', BuySubscriptionHandler::class);
$bot->onCallbackQueryData('sponsor_join_check', SponsorJoinCheckHandler::class);
$bot->onCallbackQueryData('user_referral', ReferralHandler::class);
$bot->onCallbackQueryData('referral_send_banner', ReferralHandler::class);
$bot->onCallbackQueryData('referral_claim', ReferralHandler::class);
$bot->onCallbackQueryData('select_plan_{id}', SelectPlanHandler::class);
$bot->onCallbackQueryData('pay_crypto_{id}', SelectPlanHandler::class);
$bot->onCallbackQueryData('pay_star_{id}', SelectPlanHandler::class);
$bot->onCallbackQueryData('user_stats', UserStatsHandler::class);
$bot->onCallbackQueryData('main_menu', MainMenuHandler::class);

$bot->onPreCheckoutQuery(PaymentPreCheckoutHandler::class);
$bot->onSuccessfulPayment(App\Telegram\Handlers\PaymentSuccessHandler::class);

$bot->onCallbackQueryData('admin_exit', AdminExitHandler::class);

// $bot->fallback(NotImplementedHandler::class);
