<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use SimpleStatsIo\LaravelClient\Middleware\CheckTracking;

use function Pest\Laravel\get;

it('handles referer', function ($referer, $expected) {
    Http::fake();
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    $_SERVER['HTTP_REFERER'] = $referer;

    get('/test')
        ->assertSessionHas('simplestats.tracking.referer', $expected)
        ->assertOk();
})->with([
    'handles https://fake.test' => ['https://fake.test', 'fake.test'],
    'handles https://www.fake.test' => ['https://www.fake.test', 'www.fake.test'],
    'handles www.fake.test' => ['www.fake.test', 'www.fake.test'],
    'handles fake.test' => ['fake.test', 'fake.test'],
]);
