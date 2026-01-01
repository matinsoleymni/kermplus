<?php

namespace App\Services;

class TelegramStarService
{
    private readonly float $usdPerStar;

    public function __construct(?float $usdPerStar = null)
    {
        $this->usdPerStar = $usdPerStar ?? (float) config('payments.telegram_star_usd_value', 0.5);
    }

    public static function make(): self
    {
        return new self();
    }

    public function usdToStars(float $usdAmount): int
    {
        $rate = $this->usdPerStar > 0 ? $this->usdPerStar : 0.5;

        return (int) ceil($usdAmount / $rate);
    }

    public function getUsdPerStar(): float
    {
        return $this->usdPerStar;
    }
}
