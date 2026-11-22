<?php

namespace SimpleStatsIo\LaravelClient\PaymentProviders;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Events\WebhookReceived;
use SimpleStatsIo\LaravelClient\PaymentProviders\CashierStripe\TrackStripeCharge;

class CashierStripe implements PaymentProvider
{
    public function isAvailable(): bool
    {
        return class_exists(WebhookReceived::class);
    }

    public function register(): void
    {
        Event::listen(WebhookReceived::class, [self::class, 'handleWebhookReceived']);
    }

    /**
     * Cashier Stripe stores no payments, so charges are read from its webhooks. The job fetches
     * the charge again instead of trusting the payload: its shape follows the API version of the
     * customer's webhook endpoint, and refund webhooks may arrive before the success webhook.
     */
    public function handleWebhookReceived(WebhookReceived $event): void
    {
        $object = $event->payload['data']['object'] ?? [];

        $chargeId = match ($event->payload['type'] ?? null) {
            'charge.succeeded', 'charge.captured', 'charge.refunded' => $object['id'] ?? null,
            // a failed refund restores the amount, a lost dispute takes it away
            'charge.refund.updated', 'charge.dispute.closed' => $object['charge'] ?? null,
            default => null,
        };

        if (! is_string($chargeId) || $chargeId === '') {
            return;
        }

        // Bus::dispatch() instead of ::dispatch(): a PendingDispatch would only dispatch in its
        // destructor, outside of rescue(), and a sync queue runs the job right there. A failure
        // must not fail the customer's webhook.
        rescue(fn () => Bus::dispatch(new TrackStripeCharge($chargeId)));
    }
}
