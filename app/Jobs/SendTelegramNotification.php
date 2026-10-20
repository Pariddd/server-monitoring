<?php

namespace App\Jobs;

use App\Models\AlertNotification;
use App\Services\Telegram\TelegramClient;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class SendTelegramNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $notificationId)
    {
        $this->onQueue('notifications');
    }

    /** Batas total: 30 menit sejak masuk antrean. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    /** Jeda antar-percobaan saat error jaringan atau 5xx. */
    public function backoff(): array
    {
        return [5, 15, 30, 60, 120, 300];
    }

    public function handle(TelegramClient $telegram): void
    {
        $n = AlertNotification::find($this->notificationId);
        if (! $n || $n->status === 'sent') {
            return; // sudah terkirim atau sudah dihapus
        }

        $n->attempts++;
        $n->save();

        $result = $telegram->sendMessage($n->message);

        if ($result->ok) {
            $n->forceFill([
                'status' => 'sent',
                't_sent' => CarbonImmutable::now('UTC'),
                'telegram_message_id' => $result->messageId,
                'last_error' => null,
            ])->save();

            return;
        }

        $n->forceFill(['last_error' => mb_substr($result->error(), 0, 2000)])->save();

        if ($result->status === 429) {
            $this->release(max(1, $result->retryAfter ?? 5)); // patuhi retry_after dari Telegram
            return;
        }

        if ($result->status >= 400 && $result->status < 500) {
            $this->fail(new RuntimeException($result->error())); // token/chat salah: tidak diulang
            return;
        }

        throw new RuntimeException($result->error()); // 5xx atau jaringan: diulang dengan backoff
    }

    public function failed(?Throwable $e): void
    {
        AlertNotification::whereKey($this->notificationId)
            ->where('status', '!=', 'sent')
            ->update([
                'status' => 'failed',
                'last_error' => mb_substr(TelegramClient::redact($e?->getMessage() ?? 'tidak diketahui'), 0, 2000),
            ]);
    }
}
