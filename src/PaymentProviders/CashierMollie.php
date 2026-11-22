<?php

namespace SimpleStatsIo\LaravelClient\PaymentProviders;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Events\ChargebackReceived;
use Laravel\Cashier\Events\FirstPaymentPaid;
use Laravel\Cashier\Events\OrderPaymentPaid;
use Laravel\Cashier\Events\RefundProcessed;
use Laravel\Cashier\Order\Order;
use Laravel\Cashier\Order\OrderItem;
use Laravel\Cashier\Plan\DefaultIntervalGenerator;
use Laravel\Cashier\Subscription;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;
use SimpleStatsIo\LaravelClient\Enums\SubscriptionInterval;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

/**
 * Cashier Mollie books every charge as a local order. The checkout payment only dispatches
 * FirstPaymentPaid, renewals and other mandated charges dispatch OrderPaymentPaid. Refunds and
 * chargebacks are stored on the original order, so they resend it with the remaining amount.
 */
class CashierMollie implements PaymentProvider
{
    public function isAvailable(): bool
    {
        return class_exists(OrderPaymentPaid::class);
    }

    public function register(): void
    {
        Event::listen(FirstPaymentPaid::class, [self::class, 'handleFirstPaymentPaid']);
        Event::listen(OrderPaymentPaid::class, [self::class, 'handleOrderPaymentPaid']);
        Event::listen(RefundProcessed::class, [self::class, 'handleRefundProcessed']);
        Event::listen(ChargebackReceived::class, [self::class, 'handleChargebackReceived']);
    }

    // The listeners run inside Cashier's webhook request: a failure here must not fail the
    // customer's webhook (Mollie would retry it, later listeners would not run).
    public function handleFirstPaymentPaid(FirstPaymentPaid $event): void
    {
        rescue(fn () => $this->track($event->order));
    }

    public function handleOrderPaymentPaid(OrderPaymentPaid $event): void
    {
        rescue(fn () => $this->track($event->order));
    }

    public function handleRefundProcessed(RefundProcessed $event): void
    {
        rescue(fn () => $this->track($event->refund->originalOrder));
    }

    public function handleChargebackReceived(ChargebackReceived $event): void
    {
        rescue(fn () => $this->track($event->payment->order));
    }

    public function track(?Order $order): void
    {
        $person = $order?->owner;

        // orders paid with credit alone (total_due 0) or refund orders (negative) are no payments
        if (! $order || $order->getAttribute('mollie_payment_status') !== 'paid' || $order->getAttribute('total_due') <= 0 || ! $person instanceof TrackablePerson) {
            return;
        }

        $total = (int) $order->getAttribute('total');
        $tax = (int) $order->getAttribute('tax');

        // reorder(): the relation orders by updated_at, which PostgreSQL rejects next to sum()
        $chargedBack = (int) $order->payments()->reorder()->sum('amount_charged_back');

        // total_due, not total: credit used on the order was already counted as an earlier payment
        $gross = (float) max(0, (int) $order->getAttribute('total_due') - (int) $order->getAttribute('amount_refunded') - $chargedBack);
        $net = $total > 0 ? round($gross * ($total - $tax) / $total) : $gross;

        // moneyphp stores ISK without subunits, which the zero decimal list of ProviderPayment
        // (taken from Stripe, which sends ISK with two decimals) does not cover
        if (strtoupper((string) $order->getAttribute('currency')) === 'ISK') {
            $gross *= 100;
            $net *= 100;
        }

        SimplestatsClient::trackPayment(new ProviderPayment(
            id: $order->getKey(),
            person: $person,
            time: CarbonImmutable::instance($order->getAttribute('created_at')),
            gross: $gross,
            net: $net,
            currency: $order->getAttribute('currency'),
            subscription: $this->subscription($order),
        ));
    }

    protected function subscription(Order $order): ?TrackingSubscription
    {
        // A trial checkout holds the subscription at price 0 next to the trial fee, which is no
        // subscription payment. Several subscriptions renewing together share one order.
        $item = $order->items->first(fn (OrderItem $item) => $item->total > 0 && $item->orderable instanceof Subscription);

        if (! $item) {
            return null;
        }

        /** @var Subscription $subscription */
        $subscription = $item->getRelationValue('orderable');
        $interval = $this->interval($subscription);

        return $interval ? new TrackingSubscription(plan: $subscription->getAttribute('plan'), interval: $interval) : null;
    }

    /**
     * Plans are configured as "1 month" or as ['value' => 1, 'period' => 'month'] (the advanced
     * generator, which exposes neither), so the config is read first. Plans from a custom
     * repository only resolve with the default generator.
     */
    protected function interval(Subscription $subscription): ?SubscriptionInterval
    {
        $interval = (config('cashier_plans.plans') ?? [])[$subscription->getAttribute('plan')]['interval'] ?? null;

        if ($interval === null) {
            // plan() is null (or throws in a custom repository) for a plan that no longer exists
            $generator = rescue(fn () => $subscription->plan()->interval(), report: false);
            $interval = $generator instanceof DefaultIntervalGenerator ? $generator->interval() : null;
        }

        if (is_array($interval)) {
            [$count, $period] = [(int) ($interval['value'] ?? 1), strtolower((string) ($interval['period'] ?? ''))];
        } elseif (is_string($interval) && preg_match('/^\s*(\d*)\s*(day|week|month|year)s?\s*$/i', $interval, $matches)) {
            [$count, $period] = [(int) ($matches[1] ?: 1), strtolower($matches[2])];
        } else {
            return null;
        }

        return match ([rtrim($period, 's'), $count]) {
            ['month', 1] => SubscriptionInterval::Month,
            ['month', 12], ['year', 1] => SubscriptionInterval::Year,
            default => null,
        };
    }
}
