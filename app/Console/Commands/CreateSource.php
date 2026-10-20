<?php

namespace App\Console\Commands;

use App\Models\Source;
use Illuminate\Console\Command;

class CreateSource extends Command
{
    protected $signature = 'source:create
        {name : Nama sumber, mis. H1 atau esp32-01}
        {type : host atau esp32}
        {--rotate : Buat token baru untuk sumber yang sudah ada}';

    protected $description = 'Daftarkan sumber data dan tampilkan tokennya satu kali';

    public function handle(): int
    {
        $name = $this->argument('name');
        $type = $this->argument('type');

        if (! in_array($type, ['host', 'esp32'], true)) {
            $this->error('type harus host atau esp32');
            return self::FAILURE;
        }

        $existing = Source::where('name', $name)->first();
        if ($existing && ! $this->option('rotate')) {
            $this->error("Sumber '{$name}' sudah ada. Pakai --rotate untuk membuat token baru.");
            return self::FAILURE;
        }

        $token = 'pm_' . bin2hex(random_bytes(32)); // 256 bit acak

        Source::updateOrCreate(
            ['name' => $name],
            ['type' => $type, 'token_hash' => Source::hashToken($token), 'is_active' => true]
        );

        $this->info("Sumber '{$name}' ({$type}) siap.");
        $this->warn('Token di bawah hanya tampil sekali. Simpan di config Telegraf/ESP32, jangan ditempel di chat.');
        $this->line($token);

        return self::SUCCESS;
    }
}
