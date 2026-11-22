<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;
use SimpleStatsIo\LaravelClient\PaymentProviders\CashierStripe;
use SimpleStatsIo\LaravelClient\SimplestatsClientServiceProvider;
use SimpleStatsIo\LaravelClient\Tests\Models\User;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Stripe\StripeObject;

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->string('password');
        $table->string('stripe_id')->nullable();
        $table->timestamps();
    });

    Http::fake([config('simplestats-client.api_url').'*' => Http::response([], 200)]);

    config([
        'simplestats-client.api_token' => 'foo',
        'simplestats-client.tracking_types.payment.provider' => CashierStripe::class,
        'cashier.secret' => 'sk_test',
    ]);
    Cashier::useCustomerModel(User::class);
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    User::create(['email' => 'jane@example.com', 'password' => 'secret', 'stripe_id' => 'cus_1']);

    $this->stripeResponses = [
        '/v1/charges/ch_1' => stripeCharge(),
        '/v1/invoice_payments' => ['data' => []],
    ];
    $this->stripeRequests = [];

    app()->bind(StripeClient::class, function ($app, array $parameters) {
        expect($parameters['config']['stripe_version'])->toBe('2025-03-31.basil');

        $client = Mockery::mock(StripeClient::class);
        $client->shouldReceive('request')->andReturnUsing(function (string $method, string $path, array $params) {
            $this->stripeRequests[] = $path;

            $response = $this->stripeResponses[$path] ?? throw new InvalidRequestException('No such object', 404);

            return StripeObject::constructFrom($response);
        });

        return $client;
    });
});

function stripeCharge(array $attributes = []): array
{
    return array_merge([
        'id' => 'ch_1',
        'object' => 'charge',
        'status' => 'succeeded',
        'amount' => 1190,
        'amount_captured' => 1190,
        'amount_refunded' => 0,
        'captured' => true,
        'disputed' => false,
        'currency' => 'eur',
        'customer' => 'cus_1',
        'payment_intent' => 'pi_1',
        'created' => 1795341600,
    ], $attributes);
}

function stripeSubscriptionInvoice(array $lines): array
{
    return ['data' => [['invoice' => [
        'id' => 'in_1',
        'total' => 1190,
        'total_excluding_tax' => 1000,
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']],
        'lines' => ['data' => $lines],
    ]]]];
}

function stripeSubscriptionLine(string $price, bool $proration = false): array
{
    return [
        'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['proration' => $proration]],
        'pricing' => ['type' => 'price_details', 'price_details' => ['price' => $price]],
    ];
}

function stripeWebhook(string $type = 'charge.succeeded', array $object = ['id' => 'ch_1']): void
{
    WebhookReceived::dispatch(['type' => $type, 'data' => ['object' => $object]]);
}

function sentStripePayments(): array
{
    flushDeferred();

    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request) => $request->url() === config('simplestats-client.api_url').'stats-payment')
        ->map(fn ($request) => $request->data())
        ->values()
        ->all();
}

it('tracks a one-time charge without an invoice', function () {
    stripeWebhook();

    expect(sentStripePayments())->toHaveCount(1)->sequence(fn ($payment) => $payment->toMatchArray([
        'id' => 'ch_1',
        'gross' => 1190,
        'net' => 1190,
        'currency' => 'EUR',
        'subscription_interval' => null,
        'time' => '2026-11-22 10:00:00 +00:00',
    ]));
    expect($this->stripeRequests)->toBe(['/v1/charges/ch_1', '/v1/invoice_payments']);
});

it('tracks a subscription charge with plan, interval and net from the invoice', function () {
    $this->stripeResponses['/v1/invoice_payments'] = stripeSubscriptionInvoice([
        stripeSubscriptionLine('price_old', proration: true),
        stripeSubscriptionLine('price_pro'),
    ]);
    $this->stripeResponses['/v1/prices/price_pro'] = [
        'id' => 'price_pro',
        'recurring' => ['interval' => 'year', 'interval_count' => 1],
        'product' => ['id' => 'prod_1', 'name' => 'Pro'],
    ];

    stripeWebhook();

    expect(sentStripePayments()[0])->toMatchArray([
        'gross' => 1190,
        'net' => 1000,
        'subscription_interval' => 'year',
        'subscription_plan' => 'Pro',
    ]);
});

it('sends the remaining amount after a partial refund and zero after a full one', function (int $refunded, int $gross, int $net) {
    $this->stripeResponses['/v1/charges/ch_1'] = stripeCharge(['amount_refunded' => $refunded]);
    $this->stripeResponses['/v1/invoice_payments'] = stripeSubscriptionInvoice([]);

    stripeWebhook('charge.refunded');

    expect(sentStripePayments()[0])->toMatchArray(['id' => 'ch_1', 'gross' => $gross, 'net' => $net]);
})->with([
    'partial' => [595, 595, 500],
    'full' => [1190, 0, 0],
]);

it('skips charges that did not succeed or belong to no billable', function (array $charge) {
    $this->stripeResponses['/v1/charges/ch_1'] = stripeCharge($charge);

    stripeWebhook();

    expect(sentStripePayments())->toBeEmpty();
})->with([
    'failed' => [['status' => 'failed']],
    'not captured' => [['captured' => false, 'amount_captured' => 0]],
    'no customer' => [['customer' => null]],
    'unknown customer' => [['customer' => 'cus_unknown']],
]);

it('tracks the captured amount of a partially captured charge', function () {
    $this->stripeResponses['/v1/charges/ch_1'] = stripeCharge(['amount_captured' => 800]);

    stripeWebhook('charge.captured');

    expect(sentStripePayments()[0])->toMatchArray(['gross' => 800, 'net' => 800]);
});

it('refetches the charge after a refund update or a closed dispute', function (string $type, int $gross) {
    $this->stripeResponses['/v1/charges/ch_1'] = stripeCharge(['disputed' => true]);
    $this->stripeResponses['/v1/disputes'] = ['data' => [
        ['charge' => 'ch_1', 'amount' => 1190, 'status' => $type === 'charge.dispute.closed' ? 'lost' : 'won'],
    ]];

    stripeWebhook($type, ['id' => 're_or_dp_1', 'charge' => 'ch_1']);

    expect(sentStripePayments()[0])->toMatchArray(['id' => 'ch_1', 'gross' => $gross]);
})->with([
    'failed refund' => ['charge.refund.updated', 1190],
    'lost dispute' => ['charge.dispute.closed', 0],
]);

it('ignores other webhooks', function () {
    stripeWebhook('invoice.paid');

    expect($this->stripeRequests)->toBeEmpty();
});

it('drops a charge Stripe does not know', function () {
    unset($this->stripeResponses['/v1/charges/ch_1']);

    stripeWebhook();

    expect(sentStripePayments())->toBeEmpty();
});

it('does not fail the webhook when Stripe is not reachable', function () {
    Exceptions::fake();
    app()->bind(StripeClient::class, function () {
        $client = Mockery::mock(StripeClient::class);
        $client->shouldReceive('request')->andThrow(new RuntimeException('Stripe down'));

        return $client;
    });

    stripeWebhook();

    expect(sentStripePayments())->toBeEmpty();
    Exceptions::assertReported(RuntimeException::class);
});
