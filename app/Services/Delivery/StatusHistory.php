<?php

namespace App\Services\Delivery;

use App\Enums\Delivery\DeliveryOrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * Writes the audit trail. Always called INSIDE the caller's transaction so the
 * history row commits (or rolls back) together with the status change.
 *
 * Alternative: an AFTER UPDATE OF status trigger in PostgreSQL. A trigger can
 * never be bypassed (even by manual SQL), but it hides logic from PHP
 * developers and is harder to test. For an interview: know both.
 */
final class StatusHistory
{
    public static function record(int $orderId, ?DeliveryOrderStatus $old, DeliveryOrderStatus $new): void
    {
        DB::insert(
            'INSERT INTO delivery_order_status_history (order_id, old_status, new_status) VALUES (?, ?, ?)',
            [$orderId, $old?->value, $new->value],
        );
    }
}
