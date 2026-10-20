<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramTest extends Command
{
    protected $signature = 'telegram:test';

    protected $description = 'Kirim pesan uji ke TELEGRAM_CHAT_ID';

    public function handle(TelegramClient $telegram): int
    {
        $result = $telegram->sendMessage('[UJI] Notifikasi monitoring server aktif. ' . now('UTC')->format('Y-m-d H:i:s') . 'Z');

        if (! $result->ok) {
            $this->error('Gagal: ' . $result->error());
            return self::FAILURE;
        }

        $this->info('Terkirim. message_id = ' . $result->messageId);

        return self::SUCCESS;
    }
}
