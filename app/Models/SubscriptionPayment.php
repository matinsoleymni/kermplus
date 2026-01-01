<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'provider',
        'invoice_id',
        'invoice_url',
        'payment_id',
        'status',
        'price_amount',
        'price_currency',
        'pay_amount',
        'pay_currency',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'price_amount' => 'decimal:2',
        'pay_amount' => 'decimal:8',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
