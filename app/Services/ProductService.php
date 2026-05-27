<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class ProductService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        return Product::query()
            ->with('category')
            ->when(isset($filters['search']), function ($query) use ($filters) {
                $query->where('name', 'like', '%' . $filters['search'] . '%');
            })
            ->when(isset($filters['category_id']), function ($query) use ($filters) {
                $query->where('category_id', $filters['category_id']);
            })
            ->latest()
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): Product
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);

        return Product::create($data);
    }

    public function update(Product $product, array $data): Product
    {
        if (isset($data['name']) && ! isset($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $product->update($data);

        return $product->fresh('category');
    }

    public function delete(Product $product): bool
    {
        return $product->delete();
    }
}
