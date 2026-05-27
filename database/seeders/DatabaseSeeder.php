<?php

namespace Database\Seeders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        User::factory(5)->create();

        Category::factory(5)->create();

        Product::factory(30)->create();

        Cart::factory(5)->create();

        CartItem::factory(15)->create();

        Order::factory(10)->create();

        OrderItem::factory(25)->create();

        Payment::factory(10)->create();

        $this->call([
            RolePermissionSeeder::class,
        ]);
    }
}
