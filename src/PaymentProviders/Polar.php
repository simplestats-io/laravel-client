<?php

namespace SimpleStatsIo\LaravelClient\PaymentProviders;

use Danestves\LaravelPolar\Data\Order as PolarOrder;
use Danestves\LaravelPolar\Enums\OrderStatus;
use Danestves\LaravelPolar\Enums\RecurringInterval;
use Danestves\LaravelPolar\Events\OrderPaid;
use Danestves\LaravelPolar\Events\OrderRefunded;
use Danestves\LaravelPolar\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;
use SimpleStatsIo\LaravelClient\Enums\SubscriptionInterval;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

/**
 * Polar creates an order for every charge, also for each subscription renewal, and the
 * package stores them locally. order.paid tracks it, order.refunded resends the rest.
 */
class Polar implements PaymentProvider
{
    public function isAvailable(): bool
    {
        return class_exists(OrderPaid::class);
    }

    public function register(): void
    {
        Event::listen(OrderPaid::class, [self::class, 'handleOrder']);
        Event::listen(OrderRefunded::class, [self::class, 'handleOrder']);
    }

    // The listeners run inside the package's webhook job: a failure here must not fail it
    // (it would be retried, later listeners would not run).
    public function handleOrder(OrderPaid|OrderRefunded $event): void
    {
        rescue(fn () => $this->track($event->billable, $event->order, $event->payload->data));
    }

    /**
     * The amounts come from the local order: the package skips stale webhooks when syncing it,
     * so a late order.paid cannot undo a refund. The payload adds what the order does not store.
     */
    public function track(Model $billable, Order $order, PolarOrder $data): void
    {
        $paid = in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded], true);
        $total = (int) $order->amount + (int) $order->tax_amount;

        // trial starts are paid orders of 0, orders paid with customer balance alone are no new
        // money: the balance was counted when it was paid
        if (! $paid || $total <= 0 || $data->dueAmount <= 0 || ! $billable instanceof TrackablePerson) {
            return;
        }

        // Polar refunds the net amount, the tax share separately
        $gross = max(0, $data->dueAmount - (int) $order->refunded_amount - (int) $order->refunded_tax_amount);
        $net = round($gross * (int) $order->amount / $total);

        SimplestatsClient::trackPayment(new ProviderPayment(
            id: $order->getKey(),
            person: $billable,
            // not ordered_at: the package stores the UTC value as it is, which the app reads in its own timezone
            time: $data->createdAt->utc(),
            gross: $gross,
            net: $net,
            currency: $order->currency,
            subscription: $this->subscription($data),
        ));
    }

    protected function subscription(PolarOrder $data): ?TrackingSubscription
    {
        if (! $data->subscription) {
            return null;
        }

        $interval = match ([$data->subscription->recurringInterval, $data->subscription->recurringIntervalCount]) {
            [RecurringInterval::Month, 1] => SubscriptionInterval::Month,
            [RecurringInterval::Month, 12], [RecurringInterval::Year, 1] => SubscriptionInterval::Year,
            default => null,
        };

        return $interval ? new TrackingSubscription(plan: $data->product?->name, interval: $interval) : null;
    }
}
