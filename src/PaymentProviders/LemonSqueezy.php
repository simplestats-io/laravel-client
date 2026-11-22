<?php

namespace SimpleStatsIo\LaravelClient\PaymentProviders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use LemonSqueezy\Laravel\Events\OrderCreated;
use LemonSqueezy\Laravel\Events\OrderRefunded;
use LemonSqueezy\Laravel\Events\SubscriptionPaymentSuccess;
use LemonSqueezy\Laravel\Events\WebhookReceived;
use LemonSqueezy\Laravel\LemonSqueezy as LemonSqueezyPackage;
use SimpleStatsIo\LaravelClient\PaymentProviders\LemonSqueezy\TrackLemonSqueezyPayment;

/**
 * A purchase is an order, also the first payment of a subscription. Renewals are no orders,
 * only subscription invoices, so they are tracked from those, skipping the "initial" invoice
 * that the order already counts.
 */
class LemonSqueezy implements PaymentProvider
{
    public function isAvailable(): bool
    {
        return class_exists(OrderCreated::class);
    }

    public function register(): void
    {
        Event::listen(OrderCreated::class, [self::class, 'handleOrder']);
        Event::listen(OrderRefunded::class, [self::class, 'handleOrder']);
        Event::listen(SubscriptionPaymentSuccess::class, [self::class, 'handleSubscriptionPaymentSuccess']);
        Event::listen(WebhookReceived::class, [self::class, 'handleWebhookReceived']);
    }

    public function handleOrder(OrderCreated|OrderRefunded $event): void
    {
        $attributes = $event->payload['data']['attributes'] ?? [];

        // the local order key, like a Order subclass with the contract would send it
        $id = $event->order?->getKey() ?? 'ls_order_'.($event->payload['data']['id'] ?? '');

        $this->dispatch($id, $event->billable, $attributes, $attributes['first_order_item']['variant_id'] ?? null);
    }

    public function handleSubscriptionPaymentSuccess(SubscriptionPaymentSuccess $event): void
    {
        $this->trackInvoice($event->payload, $event->billable, $event->subscription->getAttribute('variant_id'));
    }

    /**
     * The package has no handler for refunded subscription invoices.
     */
    public function handleWebhookReceived(WebhookReceived $event): void
    {
        if (($event->payload['meta']['event_name'] ?? null) !== 'subscription_payment_refunded') {
            return;
        }

        // WebhookReceived is dispatched outside the package's error handling
        rescue(function () use ($event) {
            $subscription = LemonSqueezyPackage::$subscriptionModel::where(
                // the column is a string, PostgreSQL rejects comparing it with the payload's integer
                'lemon_squeezy_id', (string) ($event->payload['data']['attributes']['subscription_id'] ?? '')
            )->first();

            if ($subscription?->billable) {
                $this->trackInvoice($event->payload, $subscription->billable, $subscription->getAttribute('variant_id'));
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function trackInvoice(array $payload, Model $billable, mixed $variantId): void
    {
        $attributes = $payload['data']['attributes'] ?? [];

        if (($attributes['billing_reason'] ?? null) === 'initial') {
            return;
        }

        $this->dispatch('ls_invoice_'.($payload['data']['id'] ?? ''), $billable, $attributes, $variantId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function dispatch(int|string $id, Model $billable, array $attributes, mixed $variantId): void
    {
        // Bus::dispatch() instead of ::dispatch(): a PendingDispatch would only dispatch in its
        // destructor, outside of rescue(). The package turns a listener exception into a 500.
        rescue(fn () => Bus::dispatch(new TrackLemonSqueezyPayment(
            $id, $billable, $attributes, $variantId !== null ? (string) $variantId : null,
        )));
    }
}
