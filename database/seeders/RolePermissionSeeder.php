<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'products.view',
            'products.create',
            'products.update',
            'products.delete',

            'orders.view',
            'orders.manage',

            'payments.view',
            'payments.refund',

            'notifications.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $admin = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $customer = Role::firstOrCreate([
            'name' => 'customer',
            'guard_name' => 'web',
        ]);

        $manager = Role::firstOrCreate([
            'name' => 'manager',
            'guard_name' => 'web',
        ]);

        $admin->givePermissionTo(Permission::all());

        $manager->givePermissionTo([
            'products.view',
            'products.create',
            'products.update',
            'orders.view',
            'orders.manage',
            'payments.view',
            'notifications.view',
        ]);

        $customer->givePermissionTo([
            'products.view',
            'orders.view',
        ]);

        $adminUser = User::where('email', 'admin@example.com')->first();

        if ($adminUser) {
            $adminUser->assignRole('admin');
        }
    }
}
