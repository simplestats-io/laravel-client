<?php

namespace SimpleStatsIo\LaravelClient\PaymentProviders\LemonSqueezy;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use LemonSqueezy\Laravel\LemonSqueezy;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;
use SimpleStatsIo\LaravelClient\Enums\SubscriptionInterval;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;
use SimpleStatsIo\LaravelClient\PaymentProviders\ProviderPayment;

class TrackLemonSqueezyPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public array $backoff = [10, 60, 600, 3600];

    /**
     * @param  array<string, mixed>  $attributes  The order or subscription invoice attributes.
     */
    public function __construct(
        public int|string $id,
        public Model $billable,
        public array $attributes,
        public ?string $variantId,
    ) {
        $this->queue = config('simplestats-client.queue');
    }

    public function handle(): void
    {
        if (! in_array($this->attributes['status'] ?? null, ['paid', 'refunded', 'partial_refund'], true) || ! $this->billable instanceof TrackablePerson) {
            return;
        }

        $total = (int) ($this->attributes['total'] ?? 0);
        $refunded = $this->attributes['refunded_amount'] ?? ($this->attributes['status'] === 'refunded' ? $total : 0);
        $gross = (float) max(0, $total - (int) $refunded);
        $net = $total > 0 ? round($gross * ($total - (int) ($this->attributes['tax'] ?? 0)) / $total) : $gross;

        SimplestatsClient::trackPayment(new ProviderPayment(
            id: $this->id,
            person: $this->billable,
            time: CarbonImmutable::parse($this->attributes['created_at'])->utc(),
            gross: $gross,
            net: $net,
            currency: strtoupper($this->attributes['currency']),
            // a deleted variant must not lose the payment (or its refund), only the subscription info
            subscription: rescue(fn () => $this->subscription()),
        ));
    }

    /**
     * Neither orders nor invoices carry the billing interval, only the variant does.
     */
    protected function subscription(): ?TrackingSubscription
    {
        if ($this->variantId === null) {
            return null;
        }

        $response = LemonSqueezy::api('GET', "variants/{$this->variantId}", ['include' => 'product'])->json();
        $variant = $response['data']['attributes'] ?? [];

        if (! ($variant['is_subscription'] ?? false)) {
            return null;
        }

        $interval = match ([$variant['interval'] ?? null, (int) ($variant['interval_count'] ?? 1)]) {
            ['month', 1] => SubscriptionInterval::Month,
            ['month', 12], ['year', 1] => SubscriptionInterval::Year,
            default => null,
        };

        if (! $interval) {
            return null;
        }

        $product = Arr::first($response['included'] ?? [], fn (array $included) => ($included['type'] ?? null) === 'products');

        return new TrackingSubscription(
            plan: $product['attributes']['name'] ?? $this->attributes['first_order_item']['product_name'] ?? null,
            interval: $interval,
        );
    }
}
