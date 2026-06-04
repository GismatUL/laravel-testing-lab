<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\NotificationLog;
use App\Models\Order;

class NotificationService
{
    public function createOrderPaidLog(Order $order): NotificationLog
    {
        return NotificationLog::create([
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'type' => NotificationType::OrderPaid->value,
            'channel' => 'email',
            'recipient' => $order->user->email,
            'status' => NotificationStatus::Pending->value,
            'message' => "Your order {$order->order_number} has been paid successfully.",
            'payload' => [
                'order_number' => $order->order_number,
                'total' => $order->total,
            ],
        ]);
    }
}
