<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramChatId extends Command
{
    protected $signature = 'telegram:chat-id';

    protected $description = 'Tampilkan chat ID dari pesan terbaru yang diterima bot';

    public function handle(TelegramClient $telegram): int
    {
        $chats = [];
        $res = $telegram->getUpdates();
        if (! $res['ok']) {
            $this->error('Gagal menghubungi Telegram: ' . $res['error']);
            return self::FAILURE;
        }

        foreach ($res['updates'] as $update) {
            $chat = $update['message']['chat'] ?? $update['my_chat_member']['chat'] ?? null;
            if ($chat) {
                $chats[$chat['id']] = [
                    $chat['id'],
                    $chat['type'],
                    $chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['username'] ?? '')),
                ];
            }
        }

        if (! $chats) {
            $this->warn('Belum ada pesan. Kirim /start ke bot (atau pesan di grup), lalu jalankan lagi.');
            return self::FAILURE;
        }

        $this->table(['chat_id', 'tipe', 'nama'], array_values($chats));

        return self::SUCCESS;
    }
}
