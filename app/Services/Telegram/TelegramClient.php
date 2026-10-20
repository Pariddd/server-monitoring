<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TelegramClient
{
    public function sendMessage(string $text): TelegramResult
    {
        $chatId = config('services.telegram.chat_id');
        if (! $this->token() || ! $chatId) {
            return new TelegramResult(false, 0, description: 'TELEGRAM_BOT_TOKEN atau TELEGRAM_CHAT_ID belum diisi');
        }

        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 4096),
        ]);
    }

    /** Dipakai perintah telegram:chat-id. */
    public function getUpdates(): array
    {
        if (! $this->token()) {
            return ['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN belum diisi', 'updates' => []];
        }

        $r = $this->request('getUpdates', []);

        if ($r['status'] === 0) {
            return ['ok' => false, 'error' => $r['error'], 'updates' => []];
        }

        if (! ($r['json']['ok'] ?? false)) {
            $desc = self::redact((string) ($r['json']['description'] ?? 'respons tidak dikenal'));
            return ['ok' => false, 'error' => 'HTTP ' . $r['status'] . ': ' . $desc, 'updates' => []];
        }

        return ['ok' => true, 'error' => null, 'updates' => $r['json']['result'] ?? []];
    }

    private function call(string $method, array $payload): TelegramResult
    {
        $r = $this->request($method, $payload);
        if ($r['status'] === 0) {
            return new TelegramResult(false, 0, description: $r['error']);
        }

        $json = $r['json'];
        if ($r['status'] >= 200 && $r['status'] < 300 && ($json['ok'] ?? false)) {
            return new TelegramResult(true, $r['status'], messageId: $json['result']['message_id'] ?? null);
        }

        return new TelegramResult(
            false,
            $r['status'],
            retryAfter: $json['parameters']['retry_after'] ?? null,
            description: self::redact((string) ($json['description'] ?? 'respons tidak dikenal')),
        );
    }

    private function request(string $method, array $payload): array
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(config('services.telegram.timeout', 10))
                ->acceptJson()
                ->post('https://api.telegram.org/bot' . $this->token() . '/' . $method, $payload);
        } catch (ConnectionException $e) {
            return ['status' => 0, 'json' => [], 'error' => self::redact($e->getMessage())];
        }

        return ['status' => $response->status(), 'json' => $response->json() ?? [], 'error' => null];
    }

    private function token(): string
    {
        return (string) config('services.telegram.bot_token');
    }

    /** Hapus token dari teks apa pun sebelum disimpan atau ditampilkan. */
    public static function redact(string $text): string
    {
        $token = (string) config('services.telegram.bot_token');

        return $token !== '' ? str_replace($token, '***', $text) : $text;
    }
}
