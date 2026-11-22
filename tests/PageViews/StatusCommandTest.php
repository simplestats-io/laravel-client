<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SimpleStatsIo\LaravelClient\PaymentProviders\CashierPaddle;
use SimpleStatsIo\LaravelClient\Services\PageViewBuffer;

beforeEach(function () {
    $this->apiStatus = 422;
    $this->apiBody = ['message' => 'The views field is required.'];

    Http::fake(fn () => Http::response($this->apiBody, $this->apiStatus));
});

it('reports a healthy setup with an active worker', function () {
    config(['queue.default' => 'database']);
    Cache::put(PageViewBuffer::HEARTBEAT_CACHE_KEY, ['at' => now()->getTimestamp() - 12], 3600);

    $this->artisan('simplestats:status')
        ->expectsOutputToContain('reachable, token valid')
        ->expectsOutputToContain(extension_loaded('pcntl') ? 'seen 12 s ago' : 'pcntl')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'stats-views') && $request['views'] === []);
});

it('shows page views as unbundled without a recent worker', function () {
    config(['queue.default' => 'database']);

    $this->artisan('simplestats:status')
        ->expectsOutputToContain('no long-running queue worker seen')
        ->assertSuccessful();
});

it('explains why page views are off on the sync queue without failing', function () {
    config(['queue.default' => 'sync']);

    $this->artisan('simplestats:status')
        ->expectsOutputToContain('Use a real queue')
        ->assertSuccessful();
});

it('shows disabled page views', function () {
    config(['simplestats-client.page_views.enabled' => false]);

    $this->artisan('simplestats:status')
        ->expectsOutputToContain('SIMPLESTATS_PAGE_VIEWS=false')
        ->assertSuccessful();
});

it('shows an active pause', function () {
    config(['queue.default' => 'database']);
    Cache::put(PageViewBuffer::PAUSE_CACHE_KEY, now()->getTimestamp() + 600, 600);

    $this->artisan('simplestats:status')
        ->expectsOutputToContain('monthly event limit is reached')
        ->assertSuccessful();
});

it('fails when the API rejects the token or cannot be reached', function (int $status) {
    $this->apiStatus = $status;

    $this->artisan('simplestats:status')->assertFailed();
})->with([401, 404, 500]);

it('fails without an API token', function () {
    config(['simplestats-client.api_token' => null]);

    $this->artisan('simplestats:status')
        ->expectsOutputToContain('SIMPLESTATS_API_TOKEN')
        ->assertFailed();

    Http::assertNothingSent();
});

it('shows the payment provider', function () {
    config(['simplestats-client.tracking_types.payment.provider' => CashierPaddle::class]);

    $this->artisan('simplestats:status')
        ->expectsOutputToContain('provider '.CashierPaddle::class)
        ->assertSuccessful();
});

it('fails on a misconfigured payment provider', function (array $payment, string $message) {
    config(['simplestats-client.tracking_types.payment' => $payment]);

    $this->artisan('simplestats:status')
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'no provider class' => [['provider' => 'App\PaymentProviders\Mollie', 'model' => null], 'is no class implementing'],
    'provider and model' => [['provider' => CashierPaddle::class, 'model' => 'App\Models\Payment'], 'payments may be tracked twice'],
]);
