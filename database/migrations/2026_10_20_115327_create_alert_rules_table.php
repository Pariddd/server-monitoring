<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();            // cpu_high, mem_high, ...
            $table->string('name', 100);
            $table->enum('kind', ['threshold', 'heartbeat']);
            $table->enum('source_type', ['host', 'esp32']);
            $table->string('metric', 32)->nullable();       // null untuk heartbeat
            $table->enum('operator', ['>', '<', '!='])->nullable();
            $table->double('threshold')->nullable();
            $table->enum('recover_operator', ['<', '>', '=='])->nullable();
            $table->double('recover_threshold')->nullable();
            $table->unsignedInteger('for_seconds');         // durasi tahan; untuk heartbeat = batas tanpa data
            $table->unsignedInteger('cooldown_seconds')->default(600);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
