<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $paymentModel = config('simplestats-client.tracking_types.payment.model');

        if (! $paymentModel || ! class_exists($paymentModel)) {
            throw new \Exception('Please define your payment model in simplestats client config before running this migration.');
        }

        Schema::table((new $paymentModel)->getTable(), function (Blueprint $table) {
            $table->string('visitor_hash', 32)->nullable();
        });
    }

    public function down(): void
    {
        $paymentModel = config('simplestats-client.tracking_types.payment.model');

        Schema::table((new $paymentModel)->getTable(), function (Blueprint $table) {
            $table->dropColumn('visitor_hash');
        });
    }
};
