<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * t_started = waktu worker antrean MULAI menjalankan percobaan pengiriman (percobaan terakhir).
 * Memisahkan ruas latensi C (tunggu antrean: t_queued → t_started) dari D (HTTP Telegram: t_started → t_sent).
 * Notifikasi lama (sebelum kolom ini ada) bernilai null; ruas C/D-nya tidak dihitung oleh alerts:latency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_notifications', function (Blueprint $table) {
            $table->dateTime('t_started', precision: 3)->nullable()->after('t_queued');
        });
    }

    public function down(): void
    {
        Schema::table('alert_notifications', function (Blueprint $table) {
            $table->dropColumn('t_started');
        });
    }
};
