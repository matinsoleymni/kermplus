<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\ReporterMenuKeyboard;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use Throwable;

class ReporterWhatIsHandler
{
    public function __invoke(Nutgram $bot): void
    {
        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $msg = <<<HTML
<tg-emoji emoji-id="4929619512224909015">🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id="4929619512224909015">🪱</tg-emoji>

<tg-emoji emoji-id="4904973211763999824">🗣️</tg-emoji> نکاتی که باید موقع ریپورت زدن رعایت کنی ( <tg-emoji emoji-id="6226426402682441481">⚠️</tg-emoji> خیلی مهم و تاثیر گذار روی نتیجه ) :

<tg-emoji emoji-id="5377620300965888937">🔴</tg-emoji>آقا بی دلیل ریپورت نزن ، وقتی یه پیجی یا کانالی یا گروهی یا اکانتی ، تخلفی که میگی رو نکرده ، قطعا توسط تیم پلتفرم چک میشه و حتی اگه ده هزار ریپورت هم زده بشه ، تا وقتی اون پیج مشکلی که میگی رو نداشته باشه هیچ وقت حذف نمیشه.

<tg-emoji emoji-id="5377620300965888937">🔴</tg-emoji> متن توضیحات ریپورتت خیلی مهمه هرچی متنش رسمی تر و به زبان انگلیسی باشه نتیجه بهتری میگیری. میتونی از Chat GPT کمک بگیری یا متن پیش فرض مارو انتخاب کنی.

<tg-emoji emoji-id="5377620300965888937">🔴</tg-emoji> پشت سر هم و رگباری ریپورت نزن ، زود زود ریپورت زدن نه تنها باعث نمیشه پیج یا اکانت یا کانال مدنظرت زودتر مسدود بشه ، بلکه باعث میشه پلتفرم شک‌ کنه که این ریپورت ها از سمت رباته و کلا قضیه کنسل بشه.

<tg-emoji emoji-id="4927262258079204871">🪱</tg-emoji> در نهایت باید بگم که تیم کرم پلاس برای هر پلتفرم صد ها اکانت تدارک دیده که بتونه بهتر و با رفتار انسانی بیشتری ریپورت بزنه براتون تا بهتر نتیجه بگیرید ، ولی در نهایت تصمیم نهایی با تیم پلتفرمه که آیا مسدود سازی انجام بشه یا نشه

<tg-emoji emoji-id="4929619512224909015">🪱</tg-emoji> @kermplus <tg-emoji emoji-id="4927295007204836791">🪱</tg-emoji>
HTML;

        $photo = $this->getReporterPhoto();
        if ($photo) {
            $bot->sendPhoto(photo: $photo);
        }

        try {
            $bot->editMessageText(
                $msg,
                parse_mode: 'HTML',
                reply_markup: ReporterMenuKeyboard::backToMenu()
            );
        } catch (Throwable) {
            $bot->sendMessage(
                $msg,
                parse_mode: 'HTML',
                reply_markup: ReporterMenuKeyboard::backToMenu()
            );
        }
    }

    private function getReporterPhoto(): ?InputFile
    {
        $path = public_path('images/reporter.png');
        return is_readable($path) ? InputFile::make($path, 'reporter.png') : null;
    }
}
