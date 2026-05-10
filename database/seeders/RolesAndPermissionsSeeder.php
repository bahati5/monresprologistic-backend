<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_shipments', 'create_shipments', 'edit_shipments', 'delete_shipments',
            'view_lockers', 'manage_lockers',
            'view_payments', 'approve_payments', 'manage_finances',
            'manage_pickups', 'assign_drivers',
            'view_regroupements', 'create_regroupements', 'manage_regroupements',
            'manage_settings', 'manage_agencies', 'manage_users', 'manage_roles',
            'view_reports', 'export_data',
            'manage_statuses', 'manage_pricing', 'manage_notifications',
            'manage_pre_alerts', 'manage_assisted_purchases',
            'manage_clients', 'manage_drivers',
            'manage_newsletter', 'manage_backups',
            'view_crm', 'view_inbound',
            'manage_customer_packages', 'view_customer_packages',
            'view_tracking',
            'manage_documents',
            'manage_refunds', 'approve_refunds',
            'manage_exchange_rates',
            'view_analytics',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $roleMatrix = [
            'super_admin' => Permission::all()->pluck('name')->all(),
            'agency_admin' => [
                'view_shipments', 'create_shipments', 'edit_shipments', 'delete_shipments',
                'view_lockers', 'manage_lockers',
                'view_payments', 'approve_payments', 'manage_finances',
                'manage_pickups', 'assign_drivers',
                'view_regroupements', 'create_regroupements', 'manage_regroupements',
                'manage_users', 'manage_roles', 'view_reports', 'export_data',
                'manage_pre_alerts', 'manage_assisted_purchases',
                'manage_statuses', 'manage_pricing', 'manage_notifications',
                'manage_agencies',
                'manage_clients', 'manage_drivers',
                'manage_newsletter',
                'view_crm', 'view_inbound',
                'manage_customer_packages', 'view_customer_packages',
                'view_tracking',
                'manage_documents',
                'manage_refunds', 'approve_refunds',
                'manage_exchange_rates',
                'view_analytics',
            ],
            'operator' => [
                'view_shipments', 'create_shipments', 'edit_shipments',
                'view_lockers', 'manage_lockers', 'view_payments',
                'manage_pre_alerts', 'manage_assisted_purchases',
                'manage_pickups', 'assign_drivers',
                'view_regroupements', 'create_regroupements',
                'view_inbound',
                'manage_customer_packages', 'view_customer_packages',
                'view_tracking',
                'manage_refunds',
                'view_reports',
            ],
            'driver' => [
                'manage_pickups', 'view_shipments', 'view_tracking',
            ],
            'customs_agent' => [
                'view_shipments', 'edit_shipments', 'view_tracking',
            ],
            'client' => [
                'view_shipments', 'view_lockers',
                'manage_pre_alerts', 'manage_assisted_purchases',
                'view_customer_packages', 'view_tracking',
            ],
        ];

        foreach ($roleMatrix as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($perms);
        }
    }
}
