<?php

namespace SimpleStatsIo\LaravelClient\PaymentProviders\CashierPaddle;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Laravel\Paddle\Cashier;
use SimpleStatsIo\LaravelClient\PaymentProviders\CashierPaddle;

class SyncPaddleAdjustment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 10;

    public array $backoff = [10, 60, 600, 3600];

    public function __construct(public string $paddleTransactionId)
    {
        $this->queue = config('simplestats-client.queue');
    }

    public function handle(CashierPaddle $provider): void
    {
        $transaction = Cashier::$transactionModel::where('paddle_id', $this->paddleTransactionId)->first();

        // the adjustment webhook can be processed before the transaction webhook stored it
        if (! $transaction) {
            $this->release(600);

            return;
        }

        $provider->track($transaction, $transaction->asPaddleTransaction());
    }
}
