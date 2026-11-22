<?php

namespace SimpleStatsIo\LaravelClient\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use SimpleStatsIo\LaravelClient\PaymentProviders\PaymentProvider;
use SimpleStatsIo\LaravelClient\PaymentProviders\PaymentProviders;
use SimpleStatsIo\LaravelClient\Services\ApiConnector;
use SimpleStatsIo\LaravelClient\Services\PageViewBuffer;
use SimpleStatsIo\LaravelClient\Services\PageViewSettings;
use Throwable;

class StatusCommand extends Command
{
    protected $signature = 'simplestats:status {--skip-api : Do not check the API connection}';

    protected $description = 'Show whether the SimpleStats client is set up correctly, including page views';

    /**
     * A worker writes its heartbeat once a minute, a long running customer job in
     * between can delay it, so only a much older one means no worker is running.
     */
    protected const WORKER_SEEN_WITHIN_SECONDS = 300;

    protected int $errors = 0;

    public function handle(PageViewSettings $settings): int
    {
        $this->newLine();

        if (! config('simplestats-client.enabled')) {
            $this->detail('Tracking', 'disabled (SIMPLESTATS_ENABLED=false)', 'comment');

            return self::SUCCESS;
        }

        $this->detail('Tracking', 'enabled', 'info');

        if (empty(config('simplestats-client.api_token'))) {
            $this->failure('API token', 'missing, set SIMPLESTATS_API_TOKEN');
        } else {
            $this->detail('API token', 'set', 'info');
        }

        $this->detail('Queue connection', sprintf('%s (driver "%s", queue "%s")',
            $settings->queueConnection() ?? 'none',
            $settings->queueDriver() ?? 'unknown',
            config('simplestats-client.queue') ?: 'default',
        ));

        $this->payments();

        $this->pageViews($settings);

        if (! $this->option('skip-api') && ! empty(config('simplestats-client.api_token'))) {
            $this->api();
        }

        $this->newLine();

        return $this->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function payments(): void
    {
        $provider = PaymentProviders::configured();
        $model = config('simplestats-client.tracking_types.payment.model');

        if ($provider === null && $model) {
            $this->detail('Payments', "model {$model}", 'info');

            return;
        }

        if ($provider === null) {
            $this->detail('Payments', 'not tracked', 'comment');

            return;
        }

        $paymentProvider = PaymentProviders::make($provider);

        if ($paymentProvider === null) {
            $this->failure('Payments', "provider {$provider} is no class implementing ".PaymentProvider::class);

            return;
        }

        if (! $paymentProvider->isAvailable()) {
            $this->failure('Payments', "provider {$provider} is set, but its package is not installed");

            return;
        }

        if ($model) {
            $this->failure('Payments', "provider {$provider} and model {$model} are both set, payments may be tracked twice. Set the model to null");

            return;
        }

        $this->detail('Payments', "provider {$provider}", 'info');
    }

    protected function pageViews(PageViewSettings $settings): void
    {
        if (! $settings->configuredEnabled()) {
            $this->detail('Page views', 'disabled (SIMPLESTATS_PAGE_VIEWS=false)', 'comment');

            return;
        }

        if (! $settings->enabled()) {
            $this->detail('Page views', sprintf(
                'off, the "%s" queue would send every view inside the web request. Use a real queue to count them',
                $settings->queueDriver(),
            ), 'comment');

            return;
        }

        $this->detail('Page views', 'enabled', 'info');
        $this->detail('Sending', ...$this->sending($settings));

        try {
            $this->detail('Pending jobs', (string) Queue::connection($settings->queueConnection())->size(config('simplestats-client.queue')));
        } catch (Throwable) {
            $this->detail('Pending jobs', 'unknown (queue size not available)');
        }

        $pausedUntil = $this->cached(PageViewBuffer::PAUSE_CACHE_KEY);

        if (is_numeric($pausedUntil) && (int) $pausedUntil > now()->getTimestamp()) {
            $this->detail('Paused', sprintf('for %d s, the monthly event limit is reached', (int) $pausedUntil - now()->getTimestamp()), 'comment');
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function sending(PageViewSettings $settings): array
    {
        if (! $settings->canBuffer()) {
            return ['one API request per view (no pcntl extension)', 'comment'];
        }

        $heartbeat = $this->cached(PageViewBuffer::HEARTBEAT_CACHE_KEY);
        $seenAt = is_array($heartbeat) && is_numeric($heartbeat['at'] ?? null) ? (int) $heartbeat['at'] : null;

        if ($seenAt === null || now()->getTimestamp() - $seenAt > self::WORKER_SEEN_WITHIN_SECONDS) {
            return ['one API request per view, no long-running queue worker seen in the last 5 minutes', 'comment'];
        }

        return [sprintf('collected by the queue worker (seen %d s ago), sent once a minute', now()->getTimestamp() - $seenAt), 'info'];
    }

    /**
     * An empty batch is rejected by validation (422) after authentication and the event
     * limit check, so it tests token and endpoint without booking anything.
     */
    protected function api(): void
    {
        try {
            $response = app(ApiConnector::class)->request('stats-views', [
                'batch_id' => (string) Str::uuid(),
                'views' => [],
            ]);
        } catch (ConnectionException) {
            $this->failure('API', 'not reachable at '.config('simplestats-client.api_url'));

            return;
        } catch (Throwable $e) {
            $this->failure('API', 'check failed: '.$e->getMessage());

            return;
        }

        match (true) {
            $response->status() === 422 => $this->detail('API', 'reachable, token valid', 'info'),
            $response->successful() && $response->json('pause') !== null => $this->detail('API', 'reachable, monthly event limit reached', 'comment'),
            $response->status() === 429 => $this->detail('API', 'reachable, rate limited right now', 'comment'),
            in_array($response->status(), [401, 403], true) => $this->failure('API', 'token rejected (HTTP '.$response->status().')'),
            $response->status() === 404 => $this->failure('API', 'page views endpoint not found, the SimpleStats server may be outdated'),
            default => $this->failure('API', 'unexpected response (HTTP '.$response->status().')'),
        };
    }

    protected function cached(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (Throwable) {
            return null;
        }
    }

    protected function failure(string $label, string $value): void
    {
        $this->errors++;

        $this->detail($label, $value, 'error');
    }

    protected function detail(string $label, string $value, ?string $style = null): void
    {
        $this->components->twoColumnDetail($label, $style ? "<{$style}>{$value}</{$style}>" : $value);
    }
}
