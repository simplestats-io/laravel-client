<?php

use hisorange\BrowserDetect\ServiceProvider as BrowserDetectServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use SimpleStatsIo\LaravelClient\Middleware\CheckTracking;
use SimpleStatsIo\LaravelClient\SimplestatsClientServiceProvider;
use SimpleStatsIo\LaravelClient\Tests\Models\User;
use SimpleStatsIo\LaravelClient\Tests\Models\UserPayment;
use SimpleStatsIo\LaravelClient\Tests\Models\UserPaymentWithCondition;
use SimpleStatsIo\LaravelClient\Tests\Models\UserWithCondition;
use SimpleStatsIo\LaravelClient\Tests\Models\VisitorPayment;
use SimpleStatsIo\LaravelClient\Tests\Models\VisitorPaymentWithCondition;
use SimpleStatsIo\LaravelClient\Visitor;

use function Pest\Laravel\get;

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });

    Schema::create('payments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
        $table->string('visitor_hash', 32)->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
    });

    $this->apiUrl = config('simplestats-client.api_url');

    Http::fake([
        $this->apiUrl.'*' => Http::response([], 200),
    ]);

    config(['simplestats-client.api_token' => 'foo']);
    config(['simplestats-client.tracking_types.user.model' => User::class]);
    // boot again to update the observers...
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();
    app()->register(BrowserDetectServiceProvider::class);

    $this->faker = \Faker\Factory::create();
});

function assertAfterDefer(Closure $callback): void
{
    if (class_exists(DeferredCallbackCollection::class)) {
        app(DeferredCallbackCollection::class)->invoke();
    }

    $callback();
}

/**
 * USER
 */
it('sends an api request if a new user gets created', function () {
    User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-user' && $request->method() == 'POST';
    }));
});

it('sends an api request if a new users condition gets fulfilled', function () {
    config(['simplestats-client.tracking_types.user.model' => UserWithCondition::class]);

    // boot again to update the observers...
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    $conditionalUser = UserWithCondition::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    Http::assertNotSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-user' && $request->method() == 'POST';
    });

    $conditionalUser->update([
        'email_verified_at' => now(),
    ]);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-user' && $request->method() == 'POST';
    }));
});

/**
 * LOGIN
 */
it('sends an api request if an user logs in', function () {
    $user = User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    $loginEvent = config('simplestats-client.tracking_types.login.event');
    event(new $loginEvent('web', $user, false));

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-login' && $request->method() == 'POST';
    }));
});

/**
 * USER PAYMENT
 */
it('sends an api request if a new user payment gets created', function () {
    config(['simplestats-client.tracking_types.payment.model' => UserPayment::class]);

    // boot again to update the observers...
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    $user = User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    UserPayment::create([
        'user_id' => $user->id,
    ]);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-payment' && $request->method() == 'POST';
    }));
});

it('sends an api request if a new user payments condition gets fulfilled', function () {
    config(['simplestats-client.tracking_types.payment.model' => UserPaymentWithCondition::class]);

    // boot again to update the observers...
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    $user = User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    $conditionalPayment = UserPaymentWithCondition::create([
        'user_id' => $user->id,
    ]);

    assertAfterDefer(fn () => Http::assertNotSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-payment' && $request->method() == 'POST';
    }));

    $conditionalPayment->update([
        'status' => 'completed',
    ]);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-payment' && $request->method() == 'POST';
    }));
});

/**
 * VISITOR PAYMENT
 */
it('sends an api request if a new visitor payment gets created', function () {
    config(['simplestats-client.tracking_types.payment.model' => VisitorPayment::class]);

    // boot again to update the observers...
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', ['user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36']);

    VisitorPayment::create([
        'visitor_hash' => session('simplestats.visitor_hash'),
    ]);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-payment' && $request->method() == 'POST';
    }));
});

it('sends an api request if a new visitor payments condition gets fulfilled', function () {
    config(['simplestats-client.tracking_types.payment.model' => VisitorPaymentWithCondition::class]);

    // boot again to update the observers...
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', ['user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36']);

    $conditionalPayment = VisitorPaymentWithCondition::create([
        'visitor_hash' => session('simplestats.visitor_hash'),
    ]);

    assertAfterDefer(fn () => Http::assertNotSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-payment' && $request->method() == 'POST';
    }));

    $conditionalPayment->update([
        'status' => 'completed',
    ]);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-payment' && $request->method() == 'POST';
    }));
});
