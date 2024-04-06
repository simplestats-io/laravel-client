<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use SimpleStatsIo\LaravelClient\SimplestatsClientServiceProvider;
use SimpleStatsIo\LaravelClient\Tests\Models\Payment;
use SimpleStatsIo\LaravelClient\Tests\Models\PaymentWithCondition;
use SimpleStatsIo\LaravelClient\Tests\Models\User;
use SimpleStatsIo\LaravelClient\Tests\Models\UserWithCondition;

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
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('status')->nullable();
        $table->timestamps();
    });

    $this->apiUrl = config('simplestats-client.api_url');

    Http::fake([
        $this->apiUrl.'*' => Http::response([], 200),
    ]);

    config(['simplestats-client.api_token' => 'foo']);
    config(['simplestats-client.tracking_types.user.model' => User::class]);
    config(['simplestats-client.tracking_types.payment.model' => Payment::class]);
    // boot again to update the observers...
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    $this->faker = \Faker\Factory::create();
});

/**
 * USER
 */
it('sends an api request if a new user gets created', function () {
    User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-user' && $request->method() == 'POST';
    });
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

    Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-user' && $request->method() == 'POST';
    });
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

    Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-login' && $request->method() == 'POST';
    });
});

/**
 * PAYMENT
 */
it('sends an api request if a new payment gets created', function () {
    $user = User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    Payment::create([
        'user_id' => $user->id,
    ]);

    Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-payment' && $request->method() == 'POST';
    });
});

it('sends an api request if a new payments condition gets fulfilled', function () {
    config(['simplestats-client.tracking_types.payment.model' => PaymentWithCondition::class]);

    // boot again to update the observers...
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    $user = User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    $conditionalPayment = PaymentWithCondition::create([
        'user_id' => $user->id,
    ]);

    Http::assertNotSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-payment' && $request->method() == 'POST';
    });

    $conditionalPayment->update([
        'status' => 'completed',
    ]);

    Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-payment' && $request->method() == 'POST';
    });
});
