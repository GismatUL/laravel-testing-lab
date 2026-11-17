<?php

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\NotificationLog;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendOrderNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];
    public int $timeout = 30;

    public function __construct(
        public readonly int $orderId,
        public readonly string $message,
    ) {
        // Not `public string $queue = '...'`: the Queueable trait already
        // declares an untyped `$queue` property, and PHP refuses to compose a
        // class where trait and class declare the same property differently
        // (fatal error at class load, so the job could never even be dispatched).
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $order = Order::with('user')->findOrFail($this->orderId);

        NotificationLog::create([
            'user_id'   => $order->user_id,
            'order_id'  => $order->id,
            'type'      => NotificationType::Email->value,
            'channel'   => 'email',
            'recipient' => $order->user->email,
            'status'    => NotificationStatus::Unread->value,
            'message'   => $this->message,
            'payload'   => [
                'order_number' => $order->order_number,
                'total'        => $order->total,
            ],
        ]);

    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendOrderNotificationJob failed after all retries', [
            'order_id' => $this->orderId,
            'message'  => $this->message,
            'error'    => $e->getMessage(),
        ]);
    }
}
