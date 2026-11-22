<?php

namespace SimpleStatsIo\LaravelClient\PaymentProviders;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Laravel\Paddle\Events\TransactionCompleted;
use Laravel\Paddle\Events\TransactionUpdated;
use Laravel\Paddle\Events\WebhookReceived;
use Laravel\Paddle\Transaction;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;
use SimpleStatsIo\LaravelClient\Enums\SubscriptionInterval;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;
use SimpleStatsIo\LaravelClient\PaymentProviders\CashierPaddle\SyncPaddleAdjustment;

class CashierPaddle implements PaymentProvider
{
    public function isAvailable(): bool
    {
        return class_exists(TransactionCompleted::class);
    }

    public function register(): void
    {
        Event::listen(TransactionCompleted::class, [self::class, 'handleTransactionCompleted']);
        Event::listen(TransactionUpdated::class, [self::class, 'handleTransactionUpdated']);
        Event::listen(WebhookReceived::class, [self::class, 'handleWebhookReceived']);
    }

    // The listeners run inside Cashier's webhook request: a failure here must not fail the
    // customer's webhook (Paddle would retry it, later listeners would not run).
    public function handleTransactionCompleted(TransactionCompleted $event): void
    {
        rescue(fn () => $this->track($event->transaction, $event->payload['data'] ?? []));
    }

    public function handleTransactionUpdated(TransactionUpdated $event): void
    {
        if ($event->transaction->wasChanged(['status', 'total', 'tax'])) {
            rescue(fn () => $this->track($event->transaction, $event->payload['data'] ?? []));
        }
    }

    /**
     * Cashier Paddle does not process refunds: Paddle books them as adjustments, which only
     * arrive as raw webhooks. Their payload holds the adjusted amount alone, so the job fetches
     * the transaction to read its adjusted totals (several partial refunds add up there).
     */
    public function handleWebhookReceived(WebhookReceived $event): void
    {
        if (! in_array($event->payload['event_type'] ?? null, ['adjustment.created', 'adjustment.updated'], true)) {
            return;
        }

        $adjustment = $event->payload['data'] ?? [];

        if (in_array($adjustment['status'] ?? null, ['pending_approval', 'rejected'], true) || empty($adjustment['transaction_id'])) {
            return;
        }

        // Bus::dispatch() instead of ::dispatch(): a PendingDispatch would only dispatch in its
        // destructor, outside of rescue(), and a sync queue runs the job right there.
        rescue(fn () => Bus::dispatch(new SyncPaddleAdjustment($adjustment['transaction_id'])));
    }

    /**
     * @param  array<string, mixed>  $data  The Paddle transaction (webhook or API), for the amounts after
     *                                      refunds and the billing cycle, which Cashier does not store.
     */
    public function track(Transaction $transaction, array $data): void
    {
        $person = $transaction->getRelationValue('billable');

        if ($transaction->getAttribute('status') !== Transaction::STATUS_COMPLETED || ! $person instanceof TrackablePerson) {
            return;
        }

        $totals = Arr::get($data, 'details.adjusted_totals') ?? Arr::get($data, 'details.totals') ?? [];
        $gross = (float) ($totals['total'] ?? $transaction->getAttribute('total'));
        $tax = (float) ($totals['tax'] ?? $transaction->getAttribute('tax'));

        SimplestatsClient::trackPayment(new ProviderPayment(
            // the model key, not paddle_id: apps that tracked a Transaction subclass before keep their ids
            id: $transaction->getKey(),
            person: $person,
            time: $this->billedAt($transaction, $data),
            gross: $gross,
            net: $gross - $tax,
            currency: $data['currency_code'] ?? $transaction->getAttribute('currency'),
            subscription: $this->subscription($data),
        ));
    }

    /**
     * Cashier stores billed_at as UTC wall clock time, but Eloquent reads it back in the app
     * timezone, which would shift the payment by the offset. The payload value is real UTC.
     *
     * @param  array<string, mixed>  $data
     */
    protected function billedAt(Transaction $transaction, array $data): CarbonImmutable
    {
        if (! empty($data['billed_at'])) {
            return CarbonImmutable::parse($data['billed_at'], 'UTC');
        }

        $billedAt = $transaction->getRawOriginal('billed_at') ?? $transaction->getRawOriginal($transaction->getCreatedAtColumn());

        return CarbonImmutable::parse($billedAt, 'UTC');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function subscription(array $data): ?TrackingSubscription
    {
        foreach ($data['items'] ?? [] as $item) {
            $cycle = $item['price']['billing_cycle'] ?? null;

            if (! $cycle) {
                continue;
            }

            $interval = match ([$cycle['interval'] ?? null, (int) ($cycle['frequency'] ?? 1)]) {
                ['month', 1] => SubscriptionInterval::Month,
                ['month', 12], ['year', 1] => SubscriptionInterval::Year,
                default => null,
            };

            if (! $interval) {
                return null;
            }

            return new TrackingSubscription(
                plan: $this->planName($data, $item['price']['id'] ?? null) ?? $item['price']['name'] ?? null,
                interval: $interval,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function planName(array $data, ?string $priceId): ?string
    {
        foreach (Arr::get($data, 'details.line_items', []) as $lineItem) {
            if (($lineItem['price_id'] ?? null) === $priceId) {
                return $lineItem['product']['name'] ?? null;
            }
        }

        return null;
    }
}
