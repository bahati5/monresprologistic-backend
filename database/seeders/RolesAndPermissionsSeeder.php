<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'shipments.view', 'shipments.create', 'shipments.edit', 'shipments.delete',
            'lockers.view', 'lockers.manage',
            'finance.view_payments', 'finance.approve_payments', 'finance.manage',
            'finance.manage_refunds', 'finance.approve_refunds', 'finance.manage_exchange_rates',
            'operations.manage_pickups', 'operations.assign_drivers',
            'operations.view_regroupements', 'operations.create_regroupements', 'operations.manage_regroupements',
            'assisted_purchase.manage', 'assisted_purchase.view_quotes',
            'assisted_purchase.create_quotes', 'assisted_purchase.approve_quotes',
            'crm.view', 'crm.manage_clients', 'crm.manage_drivers',
            'pre_alerts.manage', 'pre_alerts.view_inbound',
            'customer_packages.view', 'customer_packages.manage',
            'tracking.view',
            'reports.view', 'reports.export',
            'analytics.view',
            'admin.manage_settings', 'admin.manage_agencies', 'admin.manage_statuses',
            'admin.manage_pricing', 'admin.manage_notifications', 'admin.manage_newsletter',
            'admin.manage_backups', 'admin.manage_documents',
            'rbac.view_roles', 'rbac.manage_roles', 'rbac.manage_users',
            'rbac.view_menus', 'rbac.manage_menus',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $roleMatrix = [
            'super_admin' => Permission::all()->pluck('name')->all(),
            'agency_admin' => [
                'shipments.view', 'shipments.create', 'shipments.edit', 'shipments.delete',
                'lockers.view', 'lockers.manage',
                'finance.view_payments', 'finance.approve_payments', 'finance.manage',
                'finance.manage_refunds', 'finance.approve_refunds', 'finance.manage_exchange_rates',
                'operations.manage_pickups', 'operations.assign_drivers',
                'operations.view_regroupements', 'operations.create_regroupements', 'operations.manage_regroupements',
                'assisted_purchase.manage', 'assisted_purchase.view_quotes',
                'crm.view', 'crm.manage_clients', 'crm.manage_drivers',
                'pre_alerts.manage', 'pre_alerts.view_inbound',
                'customer_packages.view', 'customer_packages.manage',
                'tracking.view',
                'reports.view', 'reports.export',
                'analytics.view',
                'admin.manage_settings', 'admin.manage_agencies',
                'admin.manage_statuses', 'admin.manage_pricing', 'admin.manage_notifications',
                'admin.manage_newsletter', 'admin.manage_documents',
                'rbac.manage_users', 'rbac.view_roles', 'rbac.view_menus',
            ],
            'operator' => [
                'shipments.view', 'shipments.create', 'shipments.edit',
                'lockers.view', 'lockers.manage',
                'finance.view_payments',
                'operations.manage_pickups', 'operations.assign_drivers',
                'operations.view_regroupements', 'operations.create_regroupements',
                'assisted_purchase.manage',
                'pre_alerts.manage', 'pre_alerts.view_inbound',
                'customer_packages.view', 'customer_packages.manage',
                'tracking.view',
                'finance.manage_refunds',
                'reports.view',
            ],
            'driver' => [
                'operations.manage_pickups', 'shipments.view', 'tracking.view',
            ],
            'customs_agent' => [
                'shipments.view', 'shipments.edit', 'tracking.view',
            ],
            'client' => [
                'shipments.view', 'lockers.view',
                'pre_alerts.manage', 'assisted_purchase.manage',
                'customer_packages.view', 'tracking.view',
            ],
        ];

        foreach ($roleMatrix as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($perms);
        }
    }
}
