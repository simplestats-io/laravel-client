<?php

namespace SimpleStatsIo\LaravelClient\PaymentProviders\CashierStripe;

use Carbon\CarbonImmutable;
use Laravel\Cashier\Cashier;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;
use SimpleStatsIo\LaravelClient\Enums\SubscriptionInterval;
use SimpleStatsIo\LaravelClient\PaymentProviders\ProviderPayment;
use Stripe\StripeClient;

class StripeChargeResolver
{
    /**
     * Pinned, so the responses have one shape whatever Cashier version (and with it Stripe API
     * version) the app runs. Basil removed charge.invoice, invoices are found via invoice_payments.
     */
    public const API_VERSION = '2025-03-31.basil';

    protected ?StripeClient $stripe = null;

    public function resolve(string $chargeId): ?ProviderPayment
    {
        $charge = $this->get("/v1/charges/{$chargeId}");

        // An uncaptured charge is only an authorization, charge.captured brings it back.
        if (($charge['status'] ?? null) !== 'succeeded' || ! ($charge['captured'] ?? false) || empty($charge['customer'])) {
            return null;
        }

        $person = Cashier::findBillable($charge['customer']);

        if (! $person instanceof TrackablePerson) {
            return null;
        }

        $gross = (float) max(0, ($charge['amount_captured'] ?? $charge['amount']) - ($charge['amount_refunded'] ?? 0) - $this->lostDisputes($charge));
        $invoice = $this->invoice($charge);

        return new ProviderPayment(
            id: $charge['id'],
            person: $person,
            time: CarbonImmutable::createFromTimestampUTC($charge['created']),
            gross: $gross,
            net: $this->net($gross, $invoice),
            currency: strtoupper($charge['currency']),
            subscription: $invoice ? $this->subscription($invoice) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    protected function lostDisputes(array $charge): int
    {
        if (! ($charge['disputed'] ?? false)) {
            return 0;
        }

        return collect($this->get('/v1/disputes', ['charge' => $charge['id']])['data'] ?? [])
            ->where('status', 'lost')
            ->sum('amount');
    }

    /**
     * @param  array<string, mixed>  $charge
     * @return array<string, mixed>|null
     */
    protected function invoice(array $charge): ?array
    {
        if (empty($charge['payment_intent'])) {
            return null;
        }

        $payments = $this->get('/v1/invoice_payments', [
            'payment' => ['type' => 'payment_intent', 'payment_intent' => $charge['payment_intent']],
            'expand' => ['data.invoice'],
            'limit' => 1,
        ]);

        $invoice = $payments['data'][0]['invoice'] ?? null;

        return is_array($invoice) ? $invoice : null;
    }

    /**
     * The tax share of the invoice, applied to what was charged: covers partial payments,
     * customer balance and refunds. Without an invoice Stripe has no tax amount for the charge.
     *
     * @param  array<string, mixed>|null  $invoice
     */
    protected function net(float $gross, ?array $invoice): float
    {
        $total = $invoice['total'] ?? 0;
        $excludingTax = $invoice['total_excluding_tax'] ?? null;

        if ($total <= 0 || $excludingTax === null) {
            return $gross;
        }

        return round($gross * $excludingTax / $total);
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    protected function subscription(array $invoice): ?TrackingSubscription
    {
        if (empty($invoice['parent']['subscription_details']['subscription'])) {
            return null;
        }

        $priceId = $this->subscriptionPriceId($invoice['lines']['data'] ?? []);

        if ($priceId === null) {
            return null;
        }

        $price = $this->get("/v1/prices/{$priceId}", ['expand' => ['product']]);
        $recurring = $price['recurring'] ?? [];

        $interval = match ([$recurring['interval'] ?? null, (int) ($recurring['interval_count'] ?? 1)]) {
            ['month', 1] => SubscriptionInterval::Month,
            ['month', 12], ['year', 1] => SubscriptionInterval::Year,
            default => null,
        };

        if (! $interval) {
            return null;
        }

        return new TrackingSubscription(
            plan: $price['product']['name'] ?? $price['nickname'] ?? null,
            interval: $interval,
        );
    }

    /**
     * Prefers the regular subscription line over prorations, which an upgrade puts first.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function subscriptionPriceId(array $lines): ?string
    {
        $candidates = collect($lines)
            ->filter(fn (array $line) => ($line['parent']['type'] ?? null) === 'subscription_item_details'
                && is_string($line['pricing']['price_details']['price'] ?? null));

        $line = $candidates->first(fn (array $line) => ! ($line['parent']['subscription_item_details']['proration'] ?? false))
            ?? $candidates->first();

        return $line['pricing']['price_details']['price'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function get(string $path, array $params = []): array
    {
        $this->stripe ??= app(StripeClient::class, ['config' => [
            'api_key' => config('cashier.secret'),
            'stripe_version' => self::API_VERSION,
        ]]);

        return $this->stripe->request('get', $path, $params, [])->toArray();
    }
}
