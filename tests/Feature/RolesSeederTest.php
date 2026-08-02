<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_roles_are_seeded_idempotently_without_changing_admin(): void
    {
        $this->seed(PermissionsSeeder::class);
        $admin = Role::create(['name' => 'admin', 'display_name' => 'Admin']);

        $this->seed(RolesSeeder::class);
        $this->seed(RolesSeeder::class);

        $cashier = Role::where('name', 'cashier')->firstOrFail();
        $accountant = Role::where('name', 'accountant')->firstOrFail();

        $this->assertSame('قابض', $cashier->display_name);
        $this->assertEqualsCanonicalizing(
            ['manage_payments', 'manage_students', 'view_students'],
            $cashier->permissions()->pluck('name')->all()
        );
        $this->assertSame('إداري مالي', $accountant->display_name);
        $this->assertEqualsCanonicalizing(
            ['manage_expenses', 'manage_treasury', 'manage_salaries', 'manage_employees', 'view_reports'],
            $accountant->permissions()->pluck('name')->all()
        );
        $this->assertSame(2, Role::whereIn('name', ['cashier', 'accountant'])->count());
        $this->assertSame(0, $admin->permissions()->count());
    }
}
