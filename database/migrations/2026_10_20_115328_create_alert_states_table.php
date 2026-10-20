<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('tag', 64)->default('');
            $table->enum('state', ['NORMAL', 'PENDING', 'FIRING', 'RESOLVED'])->default('NORMAL');
            $table->dateTime('state_since', precision: 3)->nullable();
            $table->dateTime('breach_at')->nullable();      // t_breach: recorded_at sampel pertama yang melewati ambang
            $table->double('last_value')->nullable();
            $table->dateTime('last_notified_at', precision: 3)->nullable(); // dasar cooldown
            $table->dateTime('last_evaluated_at', precision: 3)->nullable();
            $table->timestamps();

            $table->unique(['alert_rule_id', 'source_id', 'tag']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_states');
    }
};
