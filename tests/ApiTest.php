<?php

use Faker\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use SimpleStatsIo\LaravelClient\Events\CustomEventTracked;
use SimpleStatsIo\LaravelClient\Events\CustomPropertiesTracked;
use SimpleStatsIo\LaravelClient\Events\UserTracked;
use SimpleStatsIo\LaravelClient\Events\VisitorTracked;
use SimpleStatsIo\LaravelClient\Middleware\CheckTracking;
use SimpleStatsIo\LaravelClient\SimplestatsClient;
use SimpleStatsIo\LaravelClient\SimplestatsClientServiceProvider;
use SimpleStatsIo\LaravelClient\Storage\TrackingStorage;
use SimpleStatsIo\LaravelClient\Tests\Models\User;
use SimpleStatsIo\LaravelClient\Tests\Models\UserPayment;
use SimpleStatsIo\LaravelClient\Tests\Models\UserPaymentWithCondition;
use SimpleStatsIo\LaravelClient\Tests\Models\UserWithCondition;
use SimpleStatsIo\LaravelClient\Tests\Models\VisitorPayment;
use SimpleStatsIo\LaravelClient\Tests\Models\VisitorPaymentWithCondition;
use SimpleStatsIo\LaravelClient\Tests\Resolvers\AbTestPropertiesResolver;
use SimpleStatsIo\LaravelClient\Tests\Resolvers\ThrowingUserPropertiesResolver;
use SimpleStatsIo\LaravelClient\Tests\Resolvers\UserPropertiesResolver;
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
    config(['simplestats-client.tracking_storage' => 'cache']);
    config(['simplestats-client.tracking_types.user.model' => User::class]);
    // boot again to update the observers...
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    $this->faker = Factory::create();
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

it('includes resolved custom properties in the user payload', function () {
    config(['simplestats-client.custom_properties_resolvers.user' => UserPropertiesResolver::class]);

    User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-user'
            && $request->method() == 'POST'
            && ($request['properties']['ab_test'] ?? null) == 'B'
            && ($request['properties']['company'] ?? null) == 'Acme Inc';
    }));
});

it('still tracks the user when the user properties resolver throws', function () {
    config(['simplestats-client.custom_properties_resolvers.user' => ThrowingUserPropertiesResolver::class]);

    User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-user'
            && $request->method() == 'POST'
            && empty($request->data()['properties']);
    }));
});

it('sends an api request if properties get tracked for a user', function () {
    $user = User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    app(SimplestatsClient::class)->trackCustomProperties(['ab_test' => 'B'], $user);

    assertAfterDefer(fn () => Http::assertSent(function ($request) use ($user) {
        return $request->url() == $this->apiUrl.'stats-custom-properties'
            && $request->method() == 'POST'
            && $request['stats_user_id'] == $user->getKey()
            && ($request['properties']['ab_test'] ?? null) == 'B';
    }));
});

it('sends an api request if properties get tracked for a visitor', function () {
    app(TrackingStorage::class)->put('visitor-hash-props', collect());

    app(SimplestatsClient::class)->trackCustomProperties(['ab_test' => 'B'], new Visitor('visitor-hash-props'));

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-custom-properties'
            && $request->method() == 'POST'
            && $request['visitor_hash'] == 'visitor-hash-props'
            && ($request['properties']['ab_test'] ?? null) == 'B';
    }));
});

it('does not send properties for a visitor that was never tracked', function () {
    Event::fake([CustomPropertiesTracked::class]);

    app(SimplestatsClient::class)->trackCustomProperties(['ab_test' => 'B'], new Visitor('untracked-visitor-hash'));

    assertAfterDefer(fn () => Http::assertNotSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-custom-properties';
    }));

    Event::assertNotDispatched(CustomPropertiesTracked::class);

    // no storage entry may be created, else the middleware would never track
    // this visitor on a later GET request (see CheckTracking::doTracking())
    expect(app(TrackingStorage::class)->has('untracked-visitor-hash'))->toBeFalse();
});

it('inherits visitor properties on user registration', function () {
    app(SimplestatsClient::class)->setVisitorHash('visitor-hash-inherit');
    app(TrackingStorage::class)->put('visitor-hash-inherit', collect());
    app(SimplestatsClient::class)->trackCustomProperties(['ab_test' => 'B'], new Visitor('visitor-hash-inherit'));

    User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-user'
            && $request->method() == 'POST'
            && ($request['properties']['ab_test'] ?? null) == 'B';
    }));
});

it('inherits resolved visitor properties on user registration', function () {
    config(['simplestats-client.custom_properties_resolvers.visitor' => AbTestPropertiesResolver::class]);

    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', ['user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36']);

    User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-user'
            && $request->method() == 'POST'
            && ($request['properties']['ab_test'] ?? null) == 'B';
    }));
});

it('dispatches an event for every track call', function () {
    Event::fake([VisitorTracked::class, UserTracked::class, CustomEventTracked::class, CustomPropertiesTracked::class]);

    $client = app(SimplestatsClient::class);
    app(TrackingStorage::class)->put('visitor-hash-events', collect());
    $client->trackVisitor(new Visitor('visitor-hash-events'));
    $client->trackCustomEvent('evt-1', 'Button Clicked', new Visitor('visitor-hash-events'));
    $client->trackCustomProperties(['ab_test' => 'B'], new Visitor('visitor-hash-events'));

    User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    Event::assertDispatched(VisitorTracked::class, fn ($event) => $event->payload['visitor_hash'] == 'visitor-hash-events');
    Event::assertDispatched(CustomEventTracked::class, fn ($event) => $event->name == 'Button Clicked');
    Event::assertDispatched(CustomPropertiesTracked::class, fn ($event) => ($event->properties['ab_test'] ?? null) == 'B');
    Event::assertDispatched(UserTracked::class);
});

it('lets resolved user properties win over inherited visitor properties', function () {
    config(['simplestats-client.custom_properties_resolvers.user' => UserPropertiesResolver::class]);

    app(SimplestatsClient::class)->setVisitorHash('visitor-hash-conflict');
    app(TrackingStorage::class)->put('visitor-hash-conflict', collect());
    app(SimplestatsClient::class)->trackCustomProperties(['ab_test' => 'A', 'landing_page' => 'pricing'], new Visitor('visitor-hash-conflict'));

    User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-user'
            && $request->method() == 'POST'
            && ($request['properties']['ab_test'] ?? null) == 'B'
            && ($request['properties']['landing_page'] ?? null) == 'pricing'
            && ($request['properties']['company'] ?? null) == 'Acme Inc';
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
        return $request->url() == $this->apiUrl.'stats-payment'
            && $request->method() == 'POST'
            && $request['subscription_interval'] === 'month'
            && $request['subscription_plan'] === 'pro';
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
        'visitor_hash' => app(SimplestatsClient::class)->getVisitorHash(),
    ]);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-payment'
            && $request->method() == 'POST'
            && $request['subscription_interval'] === null
            && $request['subscription_plan'] === null;
    }));
});

it('sends an api request if a new visitor payments condition gets fulfilled', function () {
    config(['simplestats-client.tracking_types.payment.model' => VisitorPaymentWithCondition::class]);

    // boot again to update the observers...
    app()->getProvider(SimplestatsClientServiceProvider::class)->boot();

    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', ['user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36']);

    $conditionalPayment = VisitorPaymentWithCondition::create([
        'visitor_hash' => app(SimplestatsClient::class)->getVisitorHash(),
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

it('does not throw when a non-trackable user logs in', function () {
    $nonTrackableUser = new class extends Illuminate\Foundation\Auth\User
    {
        protected $guarded = [];

        protected $table = 'users';
    };

    $nonTrackableUser->forceFill([
        'id' => 999,
        'email' => 'statamic@example.com',
        'password' => bcrypt('password'),
    ]);

    $loginEvent = config('simplestats-client.tracking_types.login.event');
    event(new $loginEvent('web', $nonTrackableUser, false));

    assertAfterDefer(fn () => Http::assertNotSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-login';
    }));
});

/**
 * CUSTOM EVENT
 */
it('sends an api request for a custom event with a user', function () {
    $user = User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    app(SimplestatsClient::class)->trackCustomEvent('evt-1', 'Button Clicked', $user);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-custom-event'
            && $request->method() == 'POST'
            && $request['name'] == 'Button Clicked'
            && isset($request['stats_user_id']);
    }));
});

it('sends an api request for a custom event with a visitor', function () {
    app(TrackingStorage::class)->put('abc123hash', collect());

    $visitor = new Visitor('abc123hash');

    app(SimplestatsClient::class)->trackCustomEvent('evt-2', 'Page Viewed', $visitor);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-custom-event'
            && $request->method() == 'POST'
            && $request['name'] == 'Page Viewed'
            && $request['visitor_hash'] == 'abc123hash';
    }));
});

it('still accepts the legacy user named argument for a custom event', function () {
    $user = User::create([
        'email' => $this->faker->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    // the third argument was renamed from $user to $person, the legacy
    // named-argument form must keep working
    app(SimplestatsClient::class)->trackCustomEvent(id: 'evt-legacy', name: 'Button Clicked', user: $user);

    assertAfterDefer(fn () => Http::assertSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-custom-event'
            && $request['name'] == 'Button Clicked'
            && isset($request['stats_user_id']);
    }));
});

it('throws when neither person nor user is passed to a custom event', function () {
    app(SimplestatsClient::class)->trackCustomEvent('evt-x', 'No One');
})->throws(InvalidArgumentException::class);

it('does not send a custom event for a visitor that was never tracked', function () {
    Event::fake([CustomEventTracked::class]);

    app(SimplestatsClient::class)->trackCustomEvent('evt-3', 'Page Viewed', new Visitor('untracked-visitor-hash'));

    assertAfterDefer(fn () => Http::assertNotSent(function ($request) {
        return $request->url() == $this->apiUrl.'stats-custom-event';
    }));

    Event::assertNotDispatched(CustomEventTracked::class);
});
