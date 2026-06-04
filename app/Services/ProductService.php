<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ProductService
{
    private const CACHE_TAG = 'products';
    private const CACHE_TTL = 300;

    public function list(array $filters = []): LengthAwarePaginator
    {
        $cacheKey = 'products:list:' . md5(serialize($filters));

        return Cache::tags([self::CACHE_TAG])->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn () => Product::query()
                ->with('category')
                ->when(isset($filters['search']), function ($query) use ($filters) {
                    $query->where('name', 'like', '%' . $filters['search'] . '%');
                })
                ->when(isset($filters['category_id']), function ($query) use ($filters) {
                    $query->where('category_id', $filters['category_id']);
                })
                ->latest()
                ->paginate($filters['per_page'] ?? 15)
        );
    }

    public function create(array $data): Product
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);

        $product = Product::create($data);

        $this->flushCache();

        return $product;
    }

    public function update(Product $product, array $data): Product
    {
        if (isset($data['name']) && ! isset($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $product->update($data);

        $this->flushCache();

        return $product->fresh('category');
    }

    public function delete(Product $product): bool
    {
        $result = $product->delete();

        $this->flushCache();

        return $result;
    }

    private function flushCache(): void
    {
        Cache::tags([self::CACHE_TAG])->flush();
    }
}
