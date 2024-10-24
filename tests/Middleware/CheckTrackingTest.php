<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use SimpleStatsIo\LaravelClient\Middleware\CheckTracking;

use function Pest\Laravel\get;

it('handles referer', function ($referer, $expected) {
    Http::fake();
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    $_SERVER['HTTP_REFERER'] = $referer;

    get('/test', ['user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'])
        ->assertSessionHas('simplestats.tracking.referer', $expected)
        ->assertOk();
})->with([
    'handles https://fake.test' => ['https://fake.test', 'fake.test'],
    'handles https://www.fake.test' => ['https://www.fake.test', 'fake.test'],
    'handles www.fake.test' => ['www.fake.test', 'fake.test'],
    'handles fake.test' => ['fake.test', 'fake.test'],
]);

it('does not track bots', function () {
    Http::fake();
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', ['user_agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)  # Pattern: Googlebot\/ / URL: http://www.google.com/bot.html'])
        ->assertSessionMissing('simplestats.tracking')
        ->assertOk();
});
