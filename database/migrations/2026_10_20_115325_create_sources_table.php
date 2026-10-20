<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();           // nama tampilan, mis. H1, WS-VM, esp32-01
            $table->enum('type', ['host', 'esp32']);
            $table->char('token_hash', 64)->unique();       // SHA-256 (hex) dari token; token asli tidak disimpan
            $table->string('ip_address', 45)->nullable();   // IPv4/IPv6, atribut saja (bukan identitas)
            $table->dateTime('ip_updated_at')->nullable();
            $table->dateTime('last_seen_at', precision: 3)->nullable(); // dasar aturan heartbeat
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
