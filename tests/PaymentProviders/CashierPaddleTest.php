<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Paddle\Events\TransactionCompleted;
use Laravel\Paddle\Events\TransactionUpdated;
use Laravel\Paddle\Events\WebhookReceived;
use Laravel\Paddle\Transaction;
use SimpleStatsIo\LaravelClient\PaymentProviders\CashierPaddle;
use SimpleStatsIo\LaravelClient\SimplestatsClientServiceProvider;
use SimpleStatsIo\LaravelClient\Tests\Models\User;

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('transactions', function (Blueprint $table) {
        $table->id();
        $table->morphs('billable');
        $table->string('paddle_id')->unique();
        $table->string('paddle_subscription_id')->nullable();
        $table->string('invoice_number')->nullable();
        $table->string('status');
        $table->string('total');
        $table->string('tax');
        $table->string('currency', 3);
        $table->timestamp('billed_at');
        $table->timestamps();
    });

    $this->apiUrl = config('simplestats-client.api_url');

    Http::fake([
        $this->apiUrl.'*' => Http::response([], 200),
        'https://api.paddle.com/*' => fn () => Http::response(['data' => $this->paddleTransaction]),
    ]);

    config([
        'simplestats-client.api_token' => 'foo',
        'simplestats-client.tracking_types.payment.provider' => CashierPaddle::class,
        'cashier.api_key' => 'paddle-key',
    ]);
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    $this->user = User::create(['email' => 'jane@example.com', 'password' => 'secret']);
});

function paddleTransaction(User $user, array $attributes = []): Transaction
{
    return Transaction::create(array_merge([
        'billable_type' => User::class,
        'billable_id' => $user->getKey(),
        'paddle_id' => 'txn_01',
        'status' => Transaction::STATUS_COMPLETED,
        'total' => '1190',
        'tax' => '190',
        'currency' => 'EUR',
        'billed_at' => '2026-11-20 10:00:00',
    ], $attributes));
}

function paddlePayload(array $data = []): array
{
    return ['event_type' => 'transaction.completed', 'data' => array_replace_recursive([
        'id' => 'txn_01',
        'currency_code' => 'EUR',
        'billed_at' => '2026-11-20T10:00:00.000000Z',
        'items' => [
            ['price' => ['id' => 'pri_pro', 'name' => 'Pro monthly', 'billing_cycle' => ['interval' => 'month', 'frequency' => 1]]],
        ],
        'details' => [
            'totals' => ['total' => '1190', 'tax' => '190'],
            'adjusted_totals' => ['total' => '1190', 'tax' => '190'],
            'line_items' => [['price_id' => 'pri_pro', 'product' => ['name' => 'Pro']]],
        ],
    ], $data)];
}

function sentPayments(): array
{
    flushDeferred();

    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request) => $request->url() === config('simplestats-client.api_url').'stats-payment')
        ->map(fn ($request) => $request->data())
        ->values()
        ->all();
}

it('tracks a completed transaction with its subscription', function () {
    $transaction = paddleTransaction($this->user);

    TransactionCompleted::dispatch($this->user, $transaction, paddlePayload());

    expect(sentPayments())->toHaveCount(1)->sequence(fn ($payment) => $payment->toMatchArray([
        'id' => $transaction->getKey(),
        'gross' => 1190,
        'net' => 1000,
        'currency' => 'EUR',
        'subscription_interval' => 'month',
        'subscription_plan' => 'Pro',
        'time' => '2026-11-20 10:00:00 +00:00',
    ]));
});

it('tracks a one-time transaction without subscription', function () {
    $transaction = paddleTransaction($this->user);

    TransactionCompleted::dispatch($this->user, $transaction, paddlePayload(['items' => [['price' => ['billing_cycle' => null]]]]));

    expect(sentPayments()[0])
        ->subscription_interval->toBeNull()
        ->subscription_plan->toBeNull();
});

it('tracks an interval it cannot map as one-time payment', function () {
    $transaction = paddleTransaction($this->user);

    TransactionCompleted::dispatch($this->user, $transaction, paddlePayload([
        'items' => [['price' => ['billing_cycle' => ['interval' => 'month', 'frequency' => 3]]]],
    ]));

    expect(sentPayments()[0]['subscription_interval'])->toBeNull();
});

it('tracks a twelve month cycle as yearly subscription', function () {
    $transaction = paddleTransaction($this->user);

    TransactionCompleted::dispatch($this->user, $transaction, paddlePayload([
        'items' => [['price' => ['billing_cycle' => ['interval' => 'month', 'frequency' => 12]]]],
    ]));

    expect(sentPayments()[0]['subscription_interval'])->toBe('year');
});

it('sends the payment time in UTC regardless of the app timezone', function () {
    config(['app.timezone' => 'Europe/Berlin']);
    date_default_timezone_set('Europe/Berlin');
    $transaction = paddleTransaction($this->user, ['billed_at' => '2026-11-20 23:30:00']);

    TransactionCompleted::dispatch($this->user, $transaction, paddlePayload(['billed_at' => '2026-11-20T23:30:00.000000Z']));
    TransactionCompleted::dispatch($this->user, $transaction->fresh(), ['data' => []]);

    expect(array_column(sentPayments(), 'time'))->toBe(['2026-11-20 23:30:00 +00:00', '2026-11-20 23:30:00 +00:00']);
})->after(fn () => date_default_timezone_set('UTC'));

it('does not fail the webhook when fetching the refunded transaction fails', function () {
    Exceptions::fake();
    paddleTransaction($this->user);
    config(['cashier.api_key' => null]);

    WebhookReceived::dispatch(['event_type' => 'adjustment.updated', 'data' => [
        'status' => 'approved',
        'transaction_id' => 'txn_01',
    ]]);

    expect(sentPayments())->toBeEmpty();
    Exceptions::assertReported(fn (Exception $e) => $e->getMessage() === 'Paddle API key not set.');
});

it('does not track a transaction that is not completed', function () {
    $transaction = paddleTransaction($this->user, ['status' => Transaction::STATUS_PAID]);

    TransactionCompleted::dispatch($this->user, $transaction, paddlePayload());

    expect(sentPayments())->toBeEmpty();
});

it('tracks an update only when status or amount changed', function () {
    $transaction = paddleTransaction($this->user, ['status' => Transaction::STATUS_PAID]);

    $transaction->update(['invoice_number' => 'INV-1']);
    TransactionUpdated::dispatch($this->user, $transaction, paddlePayload());

    expect(sentPayments())->toBeEmpty();

    $transaction->update(['status' => Transaction::STATUS_COMPLETED]);
    TransactionUpdated::dispatch($this->user, $transaction, paddlePayload());

    expect(sentPayments())->toHaveCount(1);
});

it('sends the adjusted amount after an approved refund', function () {
    paddleTransaction($this->user);
    $this->paddleTransaction = paddlePayload(['details' => ['adjusted_totals' => ['total' => '595', 'tax' => '95']]])['data'];

    WebhookReceived::dispatch(['event_type' => 'adjustment.updated', 'data' => [
        'action' => 'refund',
        'status' => 'approved',
        'transaction_id' => 'txn_01',
    ]]);

    expect(sentPayments())->toHaveCount(1)->sequence(fn ($payment) => $payment->toMatchArray([
        'gross' => 595,
        'net' => 500,
    ]));
});

it('ignores refunds that are not approved and other webhooks', function (string $type, string $status) {
    paddleTransaction($this->user);

    WebhookReceived::dispatch(['event_type' => $type, 'data' => [
        'status' => $status,
        'transaction_id' => 'txn_01',
    ]]);

    expect(sentPayments())->toBeEmpty();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'paddle.com'));
})->with([
    'pending refund' => ['adjustment.created', 'pending_approval'],
    'rejected refund' => ['adjustment.updated', 'rejected'],
    'other webhook' => ['subscription.updated', 'active'],
]);

it('does nothing when the provider is not configured', function () {
    config(['simplestats-client.tracking_types.payment.provider' => null]);
    app('events')->forget(TransactionCompleted::class);
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    TransactionCompleted::dispatch($this->user, paddleTransaction($this->user), paddlePayload());

    expect(sentPayments())->toBeEmpty();
});
