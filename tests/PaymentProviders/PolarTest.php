<?php

use Danestves\LaravelPolar\Data\WebhookOrderPaidPayload;
use Danestves\LaravelPolar\Data\WebhookOrderRefundedPayload;
use Danestves\LaravelPolar\Events\OrderPaid;
use Danestves\LaravelPolar\Events\OrderRefunded;
use Danestves\LaravelPolar\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use SimpleStatsIo\LaravelClient\PaymentProviders\Polar;
use SimpleStatsIo\LaravelClient\SimplestatsClientServiceProvider;
use SimpleStatsIo\LaravelClient\Tests\Models\User;
use Spatie\LaravelData\LaravelDataServiceProvider;

// danestves/laravel-polar requires PHP 8.3, so CI removes it on PHP 8.2.
beforeEach(function () {
    if (! class_exists(OrderPaid::class)) {
        $this->markTestSkipped('danestves/laravel-polar is not installed.');
    }

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('polar_orders', function (Blueprint $table) {
        $table->id();
        $table->morphs('billable');
        $table->string('polar_id')->nullable()->unique();
        $table->string('status');
        $table->integer('amount');
        $table->integer('tax_amount');
        $table->integer('refunded_amount');
        $table->integer('refunded_tax_amount');
        $table->string('currency');
        $table->string('billing_reason');
        $table->string('customer_id');
        $table->string('product_id')->nullable();
        $table->timestamp('refunded_at')->nullable();
        $table->timestamp('ordered_at');
        $table->unsignedBigInteger('polar_updated_at_us')->nullable();
        $table->timestamps();
    });

    app()->register(LaravelDataServiceProvider::class);
    Http::fake([config('simplestats-client.api_url').'*' => Http::response([], 200)]);

    config([
        'simplestats-client.api_token' => 'foo',
        'simplestats-client.tracking_types.payment.provider' => Polar::class,
    ]);
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    $this->user = User::create(['email' => 'jane@example.com', 'password' => 'secret']);
});

const POLAR_UUID = '00000000-0000-0000-0000-000000000000';

/**
 * A spec-valid Polar order (API 2026-10), shaped like the package's own test fixtures.
 */
function polarOrderData(array $overrides = [], ?array $subscription = ['month', 1]): array
{
    return array_replace([
        'id' => '11111111-1111-1111-1111-111111111111',
        'created_at' => '2026-11-20T10:00:00Z',
        'modified_at' => null,
        'status' => 'paid',
        'paid' => true,
        'subtotal_amount' => 1000,
        'discount_amount' => 0,
        'net_amount' => 1000,
        'tax_amount' => 190,
        'total_amount' => 1190,
        'applied_balance_amount' => 0,
        'due_amount' => 1190,
        'refunded_amount' => 0,
        'refunded_tax_amount' => 0,
        'currency' => 'eur',
        'billing_reason' => $subscription ? 'subscription_create' : 'purchase',
        'billing_name' => null,
        'billing_address' => null,
        'invoice_number' => null,
        'is_invoice_generated' => false,
        'receipt_number' => null,
        'customer_id' => POLAR_UUID,
        'product_id' => POLAR_UUID,
        'discount_id' => null,
        'subscription_id' => $subscription ? POLAR_UUID : null,
        'checkout_id' => null,
        'metadata' => [],
        'platform_fee_amount' => 0,
        'platform_fee_currency' => null,
        'customer' => [
            'id' => POLAR_UUID,
            'created_at' => '2026-01-01T00:00:00Z',
            'modified_at' => null,
            'metadata' => [],
            'email_verified' => false,
            'type' => 'individual',
            'name' => null,
            'billing_name' => null,
            'billing_address' => null,
            'tax_id' => null,
            'organization_id' => POLAR_UUID,
            'deleted_at' => null,
            'first_user_event_at' => null,
            'avatar_url' => null,
        ],
        'product' => [
            'metadata' => [],
            'id' => POLAR_UUID,
            'created_at' => '2026-01-01T00:00:00Z',
            'modified_at' => null,
            'trial_interval' => null,
            'trial_interval_count' => null,
            'name' => 'Pro',
            'description' => null,
            'visibility' => 'public',
            'recurring_interval' => $subscription[0] ?? null,
            'recurring_interval_count' => $subscription[1] ?? null,
            'meter_interval' => null,
            'meter_interval_count' => null,
            'is_recurring' => $subscription !== null,
            'is_archived' => false,
            'organization_id' => POLAR_UUID,
        ],
        'discount' => null,
        'subscription' => $subscription ? [
            'metadata' => [],
            'created_at' => '2026-01-01T00:00:00Z',
            'modified_at' => null,
            'id' => POLAR_UUID,
            'amount' => 1000,
            'currency' => 'eur',
            'recurring_interval' => $subscription[0],
            'recurring_interval_count' => $subscription[1],
            'status' => 'active',
            'current_period_start' => '2026-11-20T10:00:00Z',
            'current_period_end' => '2026-12-20T10:00:00Z',
            'current_meter_period_start' => null,
            'current_meter_period_end' => null,
            'trial_start' => null,
            'trial_end' => null,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'started_at' => '2026-11-20T10:00:00Z',
            'ends_at' => null,
            'ended_at' => null,
            'pause_at_period_end' => false,
            'paused_at' => null,
            'resumes_at' => null,
            'customer_id' => POLAR_UUID,
            'product_id' => POLAR_UUID,
            'discount_id' => null,
            'checkout_id' => null,
            'customer_cancellation_reason' => null,
            'customer_cancellation_comment' => null,
        ] : null,
        'items' => [],
        'description' => 'Pro',
        'refundable_amount' => 1000,
        'refundable_tax_amount' => 190,
    ], $overrides);
}

/**
 * The local order as the package syncs it from the payload.
 */
function polarOrder(User $user, array $data): Order
{
    return Order::create([
        'billable_type' => $user->getMorphClass(),
        'billable_id' => $user->getKey(),
        'polar_id' => $data['id'],
        'status' => $data['status'],
        'amount' => $data['net_amount'],
        'tax_amount' => $data['tax_amount'],
        'refunded_amount' => $data['refunded_amount'],
        'refunded_tax_amount' => $data['refunded_tax_amount'],
        'currency' => $data['currency'],
        'billing_reason' => $data['billing_reason'],
        'customer_id' => $data['customer_id'],
        'product_id' => $data['product_id'],
        'ordered_at' => $data['created_at'],
    ]);
}

function dispatchPolarOrderPaid(User $user, array $data): Order
{
    OrderPaid::dispatch($user, $order = polarOrder($user, $data), WebhookOrderPaidPayload::from([
        'type' => 'order.paid', 'timestamp' => '2026-11-20T10:00:01Z', 'data' => $data,
    ]));

    return $order;
}

function sentPolarPayments(): array
{
    flushDeferred();

    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request) => $request->url() === config('simplestats-client.api_url').'stats-payment')
        ->map(fn ($request) => $request->data())
        ->values()
        ->all();
}

it('tracks a paid order with its subscription', function () {
    $order = dispatchPolarOrderPaid($this->user, polarOrderData());

    expect(sentPolarPayments())->toHaveCount(1)->sequence(fn ($payment) => $payment->toMatchArray([
        'id' => $order->getKey(),
        'gross' => 1190,
        'net' => 1000,
        'currency' => 'EUR',
        'subscription_interval' => 'month',
        'subscription_plan' => 'Pro',
        'time' => '2026-11-20 10:00:00 +00:00',
    ]));
});

it('tracks a renewal order', function () {
    dispatchPolarOrderPaid($this->user, polarOrderData([
        'id' => '22222222-2222-2222-2222-222222222222',
        'billing_reason' => 'subscription_cycle',
    ], subscription: ['year', 1]));

    expect(sentPolarPayments()[0])->toMatchArray(['gross' => 1190, 'subscription_interval' => 'year', 'subscription_plan' => 'Pro']);
});

it('tracks a one-time purchase without subscription', function (?array $subscription) {
    dispatchPolarOrderPaid($this->user, polarOrderData(subscription: $subscription));

    expect(sentPolarPayments()[0])->toMatchArray(['gross' => 1190, 'subscription_interval' => null, 'subscription_plan' => null]);
})->with([
    'purchase' => [null],
    'unsupported interval' => [['week', 1]],
]);

it('sends the remaining amount of a refunded order', function (string $status, int $refunded, int $refundedTax, int $gross, int $net) {
    $data = polarOrderData(['status' => $status, 'refunded_amount' => $refunded, 'refunded_tax_amount' => $refundedTax]);

    OrderRefunded::dispatch($this->user, $order = polarOrder($this->user, $data), WebhookOrderRefundedPayload::from([
        'type' => 'order.refunded', 'timestamp' => '2026-11-21T10:00:00Z', 'data' => $data,
    ]));

    expect(sentPolarPayments()[0])->toMatchArray(['id' => $order->getKey(), 'gross' => $gross, 'net' => $net]);
})->with([
    'partial' => ['partially_refunded', 500, 95, 595, 500],
    'full' => ['refunded', 1000, 190, 0, 0],
]);

it('does not count customer balance used on the order again', function () {
    dispatchPolarOrderPaid($this->user, polarOrderData(['applied_balance_amount' => 595, 'due_amount' => 595]));

    expect(sentPolarPayments()[0])->toMatchArray(['gross' => 595, 'net' => 500]);
});

it('reads the amounts from the local order, which ignores stale webhooks', function () {
    $data = polarOrderData();
    $order = polarOrder($this->user, polarOrderData(['status' => 'refunded', 'refunded_amount' => 1000, 'refunded_tax_amount' => 190]));

    OrderPaid::dispatch($this->user, $order, WebhookOrderPaidPayload::from(['type' => 'order.paid', 'timestamp' => '2026-11-20T10:00:01Z', 'data' => $data]));

    expect(sentPolarPayments()[0])->toMatchArray(['gross' => 0]);
});

it('skips orders that are not paid or free', function (array $overrides) {
    dispatchPolarOrderPaid($this->user, polarOrderData($overrides));

    expect(sentPolarPayments())->toBeEmpty();
})->with([
    'pending' => [['status' => 'pending', 'paid' => false]],
    'void' => [['status' => 'void', 'paid' => false]],
    'trial start' => [['net_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0]],
    'paid with balance alone' => [['applied_balance_amount' => 1190, 'due_amount' => 0]],
]);

it('skips billables that are not trackable', function () {
    $data = polarOrderData();
    $billable = polarOrder($this->user, polarOrderData(['id' => '33333333-3333-3333-3333-333333333333']));

    OrderPaid::dispatch($billable, polarOrder($this->user, $data), WebhookOrderPaidPayload::from(['type' => 'order.paid', 'timestamp' => '2026-11-20T10:00:01Z', 'data' => $data]));

    expect(sentPolarPayments())->toBeEmpty();
});

it('does not fail the webhook when tracking fails', function () {
    Exceptions::fake();
    $data = polarOrderData();
    $order = polarOrder($this->user, $data);
    $order->setRawAttributes(['status' => 'unknown'] + $order->getAttributes());

    OrderPaid::dispatch($this->user, $order, WebhookOrderPaidPayload::from(['type' => 'order.paid', 'timestamp' => '2026-11-20T10:00:01Z', 'data' => $data]));

    expect(sentPolarPayments())->toBeEmpty();
    Exceptions::assertReported(ValueError::class);
});
