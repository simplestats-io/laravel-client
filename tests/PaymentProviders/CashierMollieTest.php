<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\CashierServiceProvider;
use Laravel\Cashier\Events\ChargebackReceived;
use Laravel\Cashier\Events\FirstPaymentPaid;
use Laravel\Cashier\Events\OrderPaymentPaid;
use Laravel\Cashier\Events\RefundProcessed;
use Laravel\Cashier\Order\Order;
use Laravel\Cashier\Order\OrderItem;
use Laravel\Cashier\Payment;
use Laravel\Cashier\Refunds\Refund;
use Laravel\Cashier\Subscription;
use Money\Currency;
use Money\Money;
use SimpleStatsIo\LaravelClient\PaymentProviders\CashierMollie;
use SimpleStatsIo\LaravelClient\SimplestatsClientServiceProvider;
use SimpleStatsIo\LaravelClient\Tests\Models\User;

// Cashier Mollie shares the Laravel\Cashier namespace with Cashier Stripe, so the two cannot be
// installed together. CI runs this file in its own job, with Cashier Mollie in place of Stripe.
beforeEach(function () {
    if (! class_exists(OrderPaymentPaid::class)) {
        $this->markTestSkipped('mollie/laravel-cashier-mollie is not installed.');
    }

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->morphs('owner');
        $table->string('number');
        $table->string('currency', 3);
        $table->integer('subtotal');
        $table->integer('tax');
        $table->integer('total');
        $table->integer('balance_before')->default(0);
        $table->integer('credit_used')->default(0);
        $table->integer('total_due');
        $table->unsignedInteger('amount_refunded')->default(0);
        $table->unsignedInteger('amount_charged_back')->default(0);
        $table->string('mollie_payment_id')->nullable();
        $table->string('mollie_payment_status', 16)->nullable();
        $table->datetime('processed_at')->nullable();
        $table->timestamps();
    });

    Schema::create('order_items', function (Blueprint $table) {
        $table->id();
        $table->dateTime('process_at');
        $table->nullableMorphs('orderable');
        $table->morphs('owner');
        $table->string('description');
        $table->string('currency', 3);
        $table->unsignedInteger('quantity')->default(1);
        $table->integer('unit_price');
        $table->decimal('tax_percentage', 6, 4);
        $table->unsignedBigInteger('order_id')->nullable();
        $table->timestamps();
    });

    Schema::create('subscriptions', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('plan');
        $table->morphs('owner');
        $table->datetime('cycle_started_at');
        $table->timestamps();
    });

    Schema::create('payments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('order_id')->nullable();
        $table->string('mollie_payment_id');
        $table->string('mollie_payment_status');
        $table->morphs('owner');
        $table->string('currency', 3);
        $table->unsignedInteger('amount')->default(0);
        $table->unsignedInteger('amount_refunded')->default(0);
        $table->unsignedInteger('amount_charged_back')->default(0);
        $table->timestamps();
    });

    app()->register(CashierServiceProvider::class);
    Http::fake([config('simplestats-client.api_url').'*' => Http::response([], 200)]);

    config([
        'simplestats-client.api_token' => 'foo',
        'simplestats-client.tracking_types.payment.provider' => CashierMollie::class,
        'cashier_plans.plans' => [
            'pro-monthly' => ['interval' => '1 month'],
            'pro-yearly' => ['interval' => ['generator' => 'advanced', 'value' => 12, 'period' => 'months']],
            'pro-weekly' => ['interval' => '1 week'],
        ],
    ]);
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    $this->user = User::create(['email' => 'jane@example.com', 'password' => 'secret']);
});

function mollieOrder(User $user, array $attributes = [], ?string $plan = 'pro-monthly'): Order
{
    $order = Order::create(array_merge([
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
        'number' => '2026-0000-0001',
        'currency' => 'EUR',
        'subtotal' => 1000,
        'tax' => 210,
        'total' => 1210,
        'total_due' => 1210,
        'mollie_payment_id' => 'tr_1',
        'mollie_payment_status' => 'paid',
        'created_at' => '2026-11-20 10:00:00',
    ], $attributes));

    $subscription = $plan ? Subscription::forceCreate([
        'name' => 'default',
        'plan' => $plan,
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
        'cycle_started_at' => '2026-11-20 10:00:00',
    ]) : null;

    OrderItem::forceCreate([
        'process_at' => '2026-11-20 10:00:00',
        'orderable_type' => $subscription?->getMorphClass(),
        'orderable_id' => $subscription?->getKey(),
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
        'description' => 'Pro',
        'currency' => 'EUR',
        'unit_price' => 1000,
        'tax_percentage' => 21,
        'order_id' => $order->getKey(),
    ]);

    return $order;
}

function molliePayment(Order $order, array $attributes = []): Payment
{
    return Payment::forceCreate(array_merge([
        'order_id' => $order->getKey(),
        'mollie_payment_id' => 'tr_1',
        'mollie_payment_status' => 'paid',
        'owner_type' => $order->owner_type,
        'owner_id' => $order->owner_id,
        'currency' => 'EUR',
        'amount' => 1210,
    ], $attributes));
}

function sentMolliePayments(): array
{
    flushDeferred();

    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request) => $request->url() === config('simplestats-client.api_url').'stats-payment')
        ->map(fn ($request) => $request->data())
        ->values()
        ->all();
}

it('tracks the first payment with its subscription', function () {
    $order = mollieOrder($this->user);

    event(new FirstPaymentPaid(null, $order));

    expect(sentMolliePayments())->toHaveCount(1)->sequence(fn ($payment) => $payment->toMatchArray([
        'id' => $order->getKey(),
        'gross' => 1210,
        'net' => 1000,
        'currency' => 'EUR',
        'subscription_interval' => 'month',
        'subscription_plan' => 'pro-monthly',
        'time' => '2026-11-20 10:00:00 +00:00',
    ]));
});

it('tracks a renewal', function () {
    $order = mollieOrder($this->user, ['number' => '2026-0000-0002'], 'pro-yearly');

    event(new OrderPaymentPaid($order, molliePayment($order)));

    expect(sentMolliePayments()[0])->toMatchArray([
        'id' => $order->getKey(),
        'gross' => 1210,
        'subscription_interval' => 'year',
        'subscription_plan' => 'pro-yearly',
    ]);
});

it('tracks a one-time charge without subscription', function (?string $plan) {
    event(new OrderPaymentPaid($order = mollieOrder($this->user, plan: $plan), molliePayment($order)));

    expect(sentMolliePayments()[0])->toMatchArray(['gross' => 1210, 'subscription_interval' => null, 'subscription_plan' => null]);
})->with([
    'no subscription' => [null],
    'unsupported interval' => ['pro-weekly'],
    'unknown plan' => ['deleted-plan'],
]);

it('tracks the trial fee of a trial checkout as one-time payment', function () {
    $order = mollieOrder($this->user, ['subtotal' => 0, 'tax' => 0, 'total' => 0, 'total_due' => 0]);
    $order->items()->update(['unit_price' => 0]);
    OrderItem::forceCreate([
        'process_at' => '2026-11-20 10:00:00',
        'owner_type' => $this->user->getMorphClass(),
        'owner_id' => $this->user->getKey(),
        'description' => 'Trial fee',
        'currency' => 'EUR',
        'unit_price' => 100,
        'tax_percentage' => 0,
        'order_id' => $order->getKey(),
    ]);
    $order->update(['subtotal' => 100, 'total' => 100, 'total_due' => 100]);

    event(new FirstPaymentPaid(null, $order));

    expect(sentMolliePayments()[0])->toMatchArray(['gross' => 100, 'subscription_interval' => null]);
});

it('only counts the amount due when credit was used', function () {
    event(new OrderPaymentPaid($order = mollieOrder($this->user, ['credit_used' => 210, 'total_due' => 1000]), molliePayment($order)));

    expect(sentMolliePayments()[0])->toMatchArray(['gross' => 1000, 'net' => 826]);
});

it('sends the remaining amount after a refund', function (int $refunded, int $gross) {
    $order = mollieOrder($this->user, ['amount_refunded' => $refunded]);
    $refund = (new Refund)->forceFill(['original_order_id' => $order->getKey()]);

    event(new RefundProcessed($refund));

    expect(sentMolliePayments()[0])->toMatchArray(['id' => $order->getKey(), 'gross' => $gross]);
})->with([
    'partial' => [605, 605],
    'full' => [1210, 0],
]);

it('subtracts chargebacks', function () {
    $payment = molliePayment($order = mollieOrder($this->user), ['amount_charged_back' => 1210]);

    event(new ChargebackReceived($payment, new Money(1210, new Currency('EUR'))));

    expect(sentMolliePayments()[0])->toMatchArray(['id' => $order->getKey(), 'gross' => 0]);
});

it('skips orders that are not paid', function (array $attributes) {
    event(new OrderPaymentPaid($order = mollieOrder($this->user, $attributes), molliePayment($order)));

    expect(sentMolliePayments())->toBeEmpty();
})->with([
    'failed' => [['mollie_payment_status' => 'failed']],
    'open' => [['mollie_payment_status' => 'open']],
    'paid with credit' => [['mollie_payment_status' => null, 'credit_used' => 1210, 'total_due' => 0]],
]);

it('skips owners that are not trackable', function () {
    $order = mollieOrder($this->user);
    $order->forceFill(['owner_type' => Order::class, 'owner_id' => $order->getKey()])->save();
    Exceptions::fake();

    event(new OrderPaymentPaid($order->fresh(), molliePayment($order)));

    expect(sentMolliePayments())->toBeEmpty();
    Exceptions::assertNothingReported();
});

it('does not fail the webhook when tracking fails', function () {
    Exceptions::fake();
    $order = mollieOrder($this->user);
    Schema::drop('order_items');

    event(new OrderPaymentPaid($order, molliePayment($order)));

    expect(sentMolliePayments())->toBeEmpty();
    Exceptions::assertReported(QueryException::class);
});

it('converts amounts of zero decimal currencies to cents', function () {
    event(new OrderPaymentPaid($order = mollieOrder($this->user, ['currency' => 'JPY', 'subtotal' => 1000, 'tax' => 100, 'total' => 1100, 'total_due' => 1100]), molliePayment($order)));

    expect(sentMolliePayments()[0])->toMatchArray(['currency' => 'JPY', 'gross' => 110000, 'net' => 100000]);
});
