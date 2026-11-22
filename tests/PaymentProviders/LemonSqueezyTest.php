<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LemonSqueezy\Laravel\Events\OrderCreated;
use LemonSqueezy\Laravel\Events\OrderRefunded;
use LemonSqueezy\Laravel\Events\SubscriptionPaymentSuccess;
use LemonSqueezy\Laravel\Events\WebhookReceived;
use LemonSqueezy\Laravel\Order;
use LemonSqueezy\Laravel\Subscription;
use SimpleStatsIo\LaravelClient\PaymentProviders\LemonSqueezy;
use SimpleStatsIo\LaravelClient\SimplestatsClientServiceProvider;
use SimpleStatsIo\LaravelClient\Tests\Models\User;

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('lemon_squeezy_subscriptions', function (Blueprint $table) {
        $table->id();
        $table->morphs('billable');
        $table->string('type');
        $table->string('lemon_squeezy_id')->unique();
        $table->string('status');
        $table->string('product_id');
        $table->string('variant_id');
        $table->timestamps();
    });

    $this->variant = ['is_subscription' => true, 'interval' => 'month', 'interval_count' => 1];

    Http::fake([
        config('simplestats-client.api_url').'*' => Http::response([], 200),
        'https://api.lemonsqueezy.com/v1/variants/*' => fn () => Http::response([
            'data' => ['type' => 'variants', 'id' => '42', 'attributes' => $this->variant],
            'included' => [['type' => 'products', 'id' => '7', 'attributes' => ['name' => 'Pro']]],
        ]),
    ]);

    config([
        'simplestats-client.api_token' => 'foo',
        'simplestats-client.tracking_types.payment.provider' => LemonSqueezy::class,
        'lemon-squeezy.api_key' => 'ls-key',
    ]);
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    $this->user = User::create(['email' => 'jane@example.com', 'password' => 'secret']);
});

function lemonSqueezyOrder(array $attributes = []): array
{
    return ['meta' => ['event_name' => 'order_created'], 'data' => ['type' => 'orders', 'id' => '1001', 'attributes' => array_merge([
        'status' => 'paid',
        'currency' => 'EUR',
        'subtotal' => 1000,
        'tax' => 190,
        'total' => 1190,
        'refunded_amount' => 0,
        'created_at' => '2026-11-20T10:00:00.000000Z',
        'first_order_item' => ['variant_id' => 42, 'product_name' => 'Pro'],
    ], $attributes)]];
}

function lemonSqueezyInvoice(string $billingReason = 'renewal', array $attributes = [], string $event = 'subscription_payment_success'): array
{
    return ['meta' => ['event_name' => $event], 'data' => ['type' => 'subscription-invoices', 'id' => '5005', 'attributes' => array_merge([
        'subscription_id' => 77,
        'billing_reason' => $billingReason,
        'status' => 'paid',
        'currency' => 'EUR',
        'tax' => 190,
        'total' => 1190,
        'refunded_amount' => 0,
        'created_at' => '2026-12-20T10:00:00.000000Z',
    ], $attributes)]];
}

function lemonSqueezySubscription(User $user): Subscription
{
    return Subscription::create([
        'billable_type' => User::class,
        'billable_id' => $user->getKey(),
        'type' => 'default',
        'lemon_squeezy_id' => '77',
        'status' => 'active',
        'product_id' => '7',
        'variant_id' => '42',
    ]);
}

function sentLemonSqueezyPayments(): array
{
    flushDeferred();

    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request) => $request->url() === config('simplestats-client.api_url').'stats-payment')
        ->map(fn ($request) => $request->data())
        ->values()
        ->all();
}

it('tracks an order with its subscription', function () {
    OrderCreated::dispatch($this->user, null, lemonSqueezyOrder());

    expect(sentLemonSqueezyPayments())->toHaveCount(1)->sequence(fn ($payment) => $payment->toMatchArray([
        'id' => 'ls_order_1001',
        'gross' => 1190,
        'net' => 1000,
        'currency' => 'EUR',
        'subscription_interval' => 'month',
        'subscription_plan' => 'Pro',
        'time' => '2026-11-20 10:00:00 +00:00',
    ]));
});

it('uses the local order key when orders are stored', function () {
    $order = (new Order)->forceFill(['id' => 7]);

    OrderCreated::dispatch($this->user, $order, lemonSqueezyOrder());

    expect(sentLemonSqueezyPayments()[0]['id'])->toBe(7);
});

it('tracks a one-time order without subscription', function () {
    $this->variant = ['is_subscription' => false, 'interval' => null, 'interval_count' => null];

    OrderCreated::dispatch($this->user, null, lemonSqueezyOrder());

    expect(sentLemonSqueezyPayments()[0])->subscription_interval->toBeNull();
});

it('sends the remaining amount of a refunded order', function (array $attributes, int $gross) {
    OrderRefunded::dispatch($this->user, null, lemonSqueezyOrder($attributes));

    expect(sentLemonSqueezyPayments()[0])->toMatchArray(['id' => 'ls_order_1001', 'gross' => $gross]);
})->with([
    'partial' => [['status' => 'refunded', 'refunded_amount' => 595], 595],
    'full' => [['status' => 'refunded', 'refunded_amount' => 1190], 0],
    'full without amount' => [['status' => 'refunded', 'refunded_amount' => null], 0],
]);

it('skips orders that are not paid', function (string $status) {
    OrderCreated::dispatch($this->user, null, lemonSqueezyOrder(['status' => $status]));

    expect(sentLemonSqueezyPayments())->toBeEmpty();
})->with(['pending', 'failed', 'fraudulent']);

it('tracks renewals but not the initial invoice the order already counts', function () {
    $subscription = lemonSqueezySubscription($this->user);

    SubscriptionPaymentSuccess::dispatch($this->user, $subscription, lemonSqueezyInvoice('initial'));
    SubscriptionPaymentSuccess::dispatch($this->user, $subscription, lemonSqueezyInvoice('renewal'));

    expect(sentLemonSqueezyPayments())->toHaveCount(1)->sequence(fn ($payment) => $payment->toMatchArray([
        'id' => 'ls_invoice_5005',
        'gross' => 1190,
        'subscription_interval' => 'month',
        'subscription_plan' => 'Pro',
        'time' => '2026-12-20 10:00:00 +00:00',
    ]));
});

it('tracks a refunded renewal from the raw webhook', function () {
    lemonSqueezySubscription($this->user);

    WebhookReceived::dispatch(lemonSqueezyInvoice('renewal', ['status' => 'refunded', 'refunded_amount' => 1190], 'subscription_payment_refunded'));

    expect(sentLemonSqueezyPayments()[0])->toMatchArray(['id' => 'ls_invoice_5005', 'gross' => 0]);
});

it('still tracks the payment when the variant cannot be fetched', function () {
    Exceptions::fake();
    config(['lemon-squeezy.api_key' => null]);

    OrderRefunded::dispatch($this->user, null, lemonSqueezyOrder(['status' => 'refunded', 'refunded_amount' => 1190]));

    expect(sentLemonSqueezyPayments()[0])->toMatchArray(['gross' => 0, 'subscription_interval' => null]);
    Exceptions::assertReported(fn (Exception $e) => $e->getMessage() === 'Lemon Squeezy API key not set.');
});

it('converts amounts of zero decimal currencies to cents', function () {
    OrderCreated::dispatch($this->user, null, lemonSqueezyOrder(['currency' => 'JPY', 'total' => 1100, 'tax' => 100]));

    expect(sentLemonSqueezyPayments()[0])->toMatchArray(['currency' => 'JPY', 'gross' => 110000, 'net' => 100000]);
});
