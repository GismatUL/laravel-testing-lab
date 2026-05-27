<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CartService
{
    public function getActiveCart(User $user): Cart
    {
        return Cart::firstOrCreate([
            'user_id' => $user->id,
            'status' => CartStatus::Active->value,
        ]);
    }

    public function addItem(User $user, Product $product, int $quantity): Cart
    {
        return DB::transaction(function () use ($user, $product, $quantity) {
            $cart = $this->getActiveCart($user);

            $item = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->first();

            if ($item) {
                $quantity += $item->quantity;
            }

            CartItem::updateOrCreate(
                [
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                ],
                [
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'total_price' => $product->price * $quantity,
                ]
            );

            return $cart->fresh('items.product');
        });
    }

    public function removeItem(CartItem $item): bool
    {
        return $item->delete();
    }

    public function clear(User $user): void
    {
        $cart = $this->getActiveCart($user);

        $cart->items()->delete();
    }
}
