<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_state_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_event_id')->nullable()->constrained()->nullOnDelete(); // null untuk pesan ulang (REPEAT)
            $table->enum('kind', ['FIRING', 'RESOLVED', 'REPEAT']);
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('message');
            $table->dateTime('t_breach')->nullable();
            $table->dateTime('t_detected', precision: 3)->nullable();
            $table->dateTime('t_queued', precision: 3);
            $table->dateTime('t_sent', precision: 3)->nullable();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 't_queued']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_notifications');
    }
};
