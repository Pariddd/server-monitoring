<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_states', function (Blueprint $table) {
            $table->dateTime('last_sample_at')->nullable()->after('last_value'); // recorded_at sampel terakhir yang sudah diproses
        });

        Schema::table('metrics', function (Blueprint $table) {
            // Query evaluator & grafik: satu sumber, satu metrik, rentang waktu, semua tag.
            $table->index(['source_id', 'metric', 'recorded_at'], 'metrics_source_metric_time_index');
        });
    }

    public function down(): void
    {
        Schema::table('metrics', function (Blueprint $table) {
            $table->dropIndex('metrics_source_metric_time_index');
        });

        Schema::table('alert_states', function (Blueprint $table) {
            $table->dropColumn('last_sample_at');
        });
    }
};
