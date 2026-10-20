<?php

namespace App\Services\Telegram;

final class TelegramResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,          // HTTP status; 0 = gagal koneksi
        public readonly ?int $messageId = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $description = null,
    ) {}

    public function error(): string
    {
        return 'HTTP ' . $this->status . ': ' . ($this->description ?? 'tanpa keterangan');
    }
}
