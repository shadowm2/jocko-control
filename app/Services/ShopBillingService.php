<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Shop;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ShopBillingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Record a payment for one billing period of the shop's plan and move its
     * paid-until date to the end of that period.
     *
     * A shop that is still paid up keeps its remaining days: the new period
     * starts the day after the current one ends. A lapsed shop starts today.
     *
     * @throws InvalidArgumentException when the shop has no plan
     */
    public function recordPayment(Shop $shop, User $admin, ?int $amount = null, ?string $note = null): SubscriptionPayment
    {
        return DB::transaction(function () use ($shop, $admin, $amount, $note): SubscriptionPayment {
            $locked = Shop::query()->with('plan')->lockForUpdate()->whereKey($shop->getKey())->firstOrFail();
            $plan = $locked->plan ?? throw new InvalidArgumentException('The shop has no plan to pay for.');

            $paidUntil = $locked->paidUntil();
            $start = $paidUntil !== null && ! $paidUntil->copy()->endOfDay()->isPast()
                ? $paidUntil->copy()->startOfDay()->addDay()
                : now()->startOfDay();
            $end = $start->copy()->addDays($plan->billing_days - 1);

            $payment = $locked->subscriptionPayments()->create([
                'plan_id' => $plan->getKey(),
                'user_id' => $admin->getKey(),
                'amount' => $amount ?? $plan->price,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'note' => filled($note) ? trim((string) $note) : null,
            ]);

            $locked->forceFill(['paid_until' => $end->toDateString()])->save();

            $this->audit->record(AuditAction::ShopPaymentRecorded, $admin, $locked, metadata: [
                'amount' => $payment->amount,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
            ]);

            return $payment;
        });
    }
}
