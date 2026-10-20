<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 32);                   // cpu_usage, mem_used_pct, disk_free_pct, service_state, temperature, humidity
            $table->string('tag', 64)->default('');         // volume (C:) atau nama layanan; '' bila tidak ada
            $table->double('value');
            $table->dateTime('recorded_at');                // stempel waktu data dari Telegraf/ESP32 (UTC, detik)
            $table->dateTime('received_at', precision: 3);  // waktu diterima Laravel (UTC, milidetik)

            // Mencegah duplikat saat Telegraf mengirim ulang, sekaligus indeks utama query per seri.
            $table->unique(['source_id', 'metric', 'tag', 'recorded_at'], 'metrics_series_time_unique');
            // Untuk pembersihan data lama.
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics');
    }
};
