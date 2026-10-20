<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_state_id')->constrained()->cascadeOnDelete();
            $table->enum('from_state', ['NORMAL', 'PENDING', 'FIRING', 'RESOLVED']);
            $table->enum('to_state', ['NORMAL', 'PENDING', 'FIRING', 'RESOLVED']);
            $table->double('value')->nullable();
            $table->dateTime('t_breach')->nullable();               // waktu data yang melewati ambang
            $table->dateTime('t_detected', precision: 3);           // waktu evaluator mendeteksi transisi

            $table->index('t_detected');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
    }
};
