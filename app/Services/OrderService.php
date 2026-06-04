<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OrderService
{
    public function __construct(
        protected CartService $cartService
    ) {}

    public function createFromCart(User $user): Order
    {
        return DB::transaction(function () use ($user) {
            $cart = $this->cartService
                ->getActiveCart($user)
                ->load('items.product');

            if ($cart->items->isEmpty()) {
                throw new RuntimeException('Cart is empty.');
            }

            $subtotal = $cart->items->sum('total_price');

            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => 'ORD-' . now()->format('YmdHis') . '-' . Str::random(6),
                'status' => OrderStatus::Pending->value,
                'subtotal' => $subtotal,
                'total' => $subtotal,
            ]);

            foreach ($cart->items as $item) {
                $product = $item->product()->lockForUpdate()->first();

                if ($product->stock < $item->quantity) {
                    throw new RuntimeException("Not enough stock for {$product->name}.");
                }

                $product->decrement('stock', $item->quantity);

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $item->unit_price,
                    'quantity' => $item->quantity,
                    'total_price' => $item->total_price,
                ]);
            }

            $cart->update([
                'status' => CartStatus::Converted->value,
            ]);

            return $order->fresh('items.product');
        });
    }
}
