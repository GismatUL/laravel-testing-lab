<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddToCartRequest;
use App\Http\Resources\CartResource;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        protected CartService $cartService
    ) {}

    public function show(Request $request): CartResource
    {
        $cart = $this->cartService
            ->getActiveCart($request->user())
            ->load('items.product');

        return new CartResource($cart);
    }

    public function addItem(AddToCartRequest $request): CartResource
    {
        $product = Product::findOrFail($request->product_id);

        $cart = $this->cartService->addItem(
            $request->user(),
            $product,
            $request->quantity
        );

        return new CartResource($cart->load('items.product'));
    }

    public function removeItem(Request $request, CartItem $item): JsonResponse
    {
        $cart = $this->cartService->getActiveCart($request->user());

        if ($item->cart_id !== $cart->id) {
            abort(403);
        }

        $this->cartService->removeItem($item);

        return response()->json(null, 204);
    }

    public function clear(Request $request): JsonResponse
    {
        $this->cartService->clear($request->user());

        return response()->json(null, 204);
    }
}
