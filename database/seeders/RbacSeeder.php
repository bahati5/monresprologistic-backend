<?php

namespace Database\Seeders;

use App\Models\FrontendElement;
use App\Models\Menu;
use App\Models\PermissionGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ── 1. Permissions (module.action format only) ──

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
            'sav.view', 'sav.manage', 'sav.client',
            'suivi.view',
            'admin.manage_settings', 'admin.manage_agencies', 'admin.manage_statuses',
            'admin.manage_pricing', 'admin.manage_notifications', 'admin.manage_newsletter',
            'admin.manage_backups', 'admin.manage_documents',
            'rbac.view_roles', 'rbac.manage_roles', 'rbac.manage_users',
            'rbac.view_menus', 'rbac.manage_menus',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // ── 2. Permission Groups ──

        $groups = [
            'group_operations_base' => [
                'name' => 'Opérations de base',
                'description' => 'Permissions de base pour les opérations quotidiennes',
                'permissions' => ['shipments.view', 'shipments.create', 'shipments.edit', 'tracking.view', 'lockers.view'],
            ],
            'group_operations_full' => [
                'name' => 'Opérations complètes',
                'description' => 'Toutes les permissions opérationnelles',
                'permissions' => [
                    'shipments.view', 'shipments.create', 'shipments.edit', 'shipments.delete',
                    'tracking.view', 'lockers.view', 'lockers.manage',
                    'operations.manage_pickups', 'operations.assign_drivers',
                    'operations.view_regroupements', 'operations.create_regroupements', 'operations.manage_regroupements',
                    'sav.view', 'sav.manage',
                    'suivi.view',
                ],
            ],
            'group_finance_view' => [
                'name' => 'Finance — lecture',
                'description' => 'Accès en lecture aux données financières',
                'permissions' => ['finance.view_payments'],
            ],
            'group_finance_full' => [
                'name' => 'Finance complète',
                'description' => 'Toutes les permissions financières',
                'permissions' => [
                    'finance.view_payments', 'finance.approve_payments', 'finance.manage',
                    'finance.manage_refunds', 'finance.approve_refunds', 'finance.manage_exchange_rates',
                ],
            ],
            'group_crm' => [
                'name' => 'CRM',
                'description' => 'Gestion de la relation client',
                'permissions' => ['crm.view', 'crm.manage_clients', 'crm.manage_drivers', 'customer_packages.view', 'customer_packages.manage'],
            ],
            'group_admin' => [
                'name' => 'Administration',
                'description' => 'Permissions d\'administration système',
                'permissions' => [
                    'admin.manage_settings', 'admin.manage_agencies', 'admin.manage_statuses',
                    'admin.manage_pricing', 'admin.manage_notifications', 'admin.manage_newsletter',
                    'admin.manage_backups', 'admin.manage_documents',
                    'rbac.view_roles', 'rbac.manage_roles', 'rbac.manage_users', 'rbac.view_menus', 'rbac.manage_menus',
                ],
            ],
            'group_reports' => [
                'name' => 'Rapports',
                'description' => 'Accès aux rapports et analytiques',
                'permissions' => ['reports.view', 'reports.export', 'analytics.view'],
            ],
            'group_client' => [
                'name' => 'Accès client',
                'description' => 'Portail client : suivi de ses dossiers, SAV, lecture expéditions (sans création)',
                'permissions' => [
                    'shipments.view',
                    'sav.client',
                ],
            ],
        ];

        foreach ($groups as $code => $data) {
            $group = PermissionGroup::firstOrCreate(
                ['code' => $code],
                ['name' => $data['name'], 'description' => $data['description']]
            );

            $permIds = Permission::whereIn('name', $data['permissions'])->pluck('id');
            $group->permissions()->syncWithoutDetaching($permIds);
        }

        // ── 3. Roles with groups + direct permissions ──

        $roleConfigs = [
            'super_admin' => [
                'code' => 'super_admin',
                'description' => 'Accès complet à toutes les fonctionnalités',
                'is_system' => true,
                'level' => 100,
                'groups' => ['group_operations_full', 'group_finance_full', 'group_crm', 'group_admin', 'group_reports'],
                'permissions' => Permission::all()->pluck('name')->all(),
            ],
            'agency_admin' => [
                'code' => 'agency_admin',
                'description' => 'Gestion complète d\'une agence',
                'is_system' => true,
                'level' => 80,
                'groups' => ['group_operations_full', 'group_finance_full', 'group_crm', 'group_reports'],
                'permissions' => ['rbac.manage_users', 'admin.manage_agencies', 'rbac.view_roles', 'rbac.view_menus'],
            ],
            'operator' => [
                'code' => 'operator',
                'description' => 'Opérateur logistique quotidien',
                'is_system' => true,
                'level' => 60,
                'groups' => ['group_operations_base', 'group_finance_view'],
                'permissions' => [
                    'operations.manage_pickups', 'operations.assign_drivers',
                    'pre_alerts.manage', 'pre_alerts.view_inbound',
                    'assisted_purchase.manage', 'reports.view',
                    'customer_packages.view', 'customer_packages.manage',
                    'sav.view', 'sav.manage',
                ],
            ],
            'driver' => [
                'code' => 'driver',
                'description' => 'Chauffeur-livreur',
                'is_system' => true,
                'level' => 40,
                'groups' => [],
                'permissions' => ['operations.manage_pickups', 'shipments.view', 'tracking.view'],
            ],
            'customs_agent' => [
                'code' => 'customs_agent',
                'description' => 'Agent de douane',
                'is_system' => true,
                'level' => 40,
                'groups' => [],
                'permissions' => ['shipments.view', 'shipments.edit', 'tracking.view'],
            ],
            'client' => [
                'code' => 'client',
                'description' => 'Client MonResPro',
                'is_system' => true,
                'level' => 10,
                'groups' => ['group_client'],
                'permissions' => [],
            ],
        ];

        foreach ($roleConfigs as $roleName => $config) {
            $role = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
            );

            $role->update([
                'uuid' => $role->uuid ?: (string) Str::uuid(),
                'code' => $config['code'],
                'description' => $config['description'],
                'is_system' => $config['is_system'],
                'level' => $config['level'],
            ]);

            if (! empty($config['permissions'])) {
                $role->syncPermissions($config['permissions']);
            }

            if (! empty($config['groups'])) {
                $groupIds = PermissionGroup::whereIn('code', $config['groups'])->pluck('id');
                $role->permissionGroups()->syncWithoutDetaching($groupIds);
            }
        }

        // ── 4. Menus ──

        $menuConfigs = [
            ['code' => 'general', 'name' => 'Général', 'icon' => 'LayoutDashboard', 'order' => 0],
            ['code' => 'services_clients', 'name' => 'Services clients', 'icon' => 'ShoppingBag', 'order' => 5],
            ['code' => 'operations', 'name' => 'Opérations', 'icon' => 'Package', 'order' => 10],
            ['code' => 'suivi', 'name' => 'Suivi', 'icon' => 'Eye', 'order' => 15],
            ['code' => 'crm_finance', 'name' => 'CRM & Finance', 'icon' => 'Users', 'order' => 30],
            ['code' => 'analytics', 'name' => 'Analytique', 'icon' => 'BarChart3', 'order' => 40],
            ['code' => 'users_module', 'name' => 'Utilisateurs', 'icon' => 'Users', 'order' => 50],
            ['code' => 'administration', 'name' => 'Administration', 'icon' => 'Settings', 'order' => 60],
        ];

        $menus = [];
        foreach ($menuConfigs as $m) {
            $menus[$m['code']] = Menu::updateOrCreate(['code' => $m['code']], $m);
        }

        $oldMenu = Menu::where('code', 'client_services')->first();
        if ($oldMenu) {
            FrontendElement::where('menu_id', $oldMenu->id)->update(['menu_id' => $menus['suivi']->id]);
            $oldMenu->delete();
        }

        FrontendElement::where('code', 'refunds')->delete();

        // ── 5. Frontend Elements ──

        $elements = [
            [
                'code' => 'dashboard', 'name' => 'Tableau de bord', 'route' => '/dashboard',
                'icon' => 'LayoutDashboard', 'order' => 0, 'menu' => 'general', 'permissions' => [],
            ],
            [
                'code' => 'profile', 'name' => 'Profil', 'route' => '/profile',
                'icon' => 'UserCircle', 'order' => 1, 'menu' => null, 'permissions' => [],
                'display_in_sidebar' => false,
            ],
            [
                'code' => 'assisted_purchases_list', 'name' => 'Shopping Assisté', 'route' => '/purchase-orders',
                'icon' => 'ShoppingBag', 'order' => 10, 'menu' => 'services_clients',
                'permissions' => ['assisted_purchase.manage'],
            ],
            [
                'code' => 'sav_tickets', 'name' => 'SAV', 'route' => '/sav',
                'icon' => 'HeadphonesIcon', 'order' => 11, 'menu' => 'services_clients',
                'permissions' => ['sav.view', 'sav.manage'],
            ],
            [
                'code' => 'shipments_list', 'name' => 'Expéditions', 'route' => '/shipments',
                'icon' => 'Package', 'order' => 10, 'menu' => 'operations',
                'permissions' => ['shipments.view'],
            ],
            [
                'code' => 'regroupements', 'name' => 'Regroupements', 'route' => '/regroupements',
                'icon' => 'Layers', 'order' => 11, 'menu' => 'operations',
                'permissions' => ['operations.view_regroupements'],
            ],
            [
                'code' => 'pickups', 'name' => 'Ramassages', 'route' => '/pickups',
                'icon' => 'Truck', 'order' => 12, 'menu' => 'operations',
                'permissions' => ['operations.manage_pickups'],
            ],
            [
                'code' => 'suivi_dashboard', 'name' => 'Tableau de suivi', 'route' => '/monitoring',
                'icon' => 'Eye', 'order' => 20, 'menu' => 'suivi',
                'permissions' => ['suivi.view'],
            ],
            [
                'code' => 'clients_directory', 'name' => 'Annuaire Clients', 'route' => '/clients',
                'icon' => 'Users', 'order' => 30, 'menu' => 'crm_finance',
                'permissions' => ['crm.view'],
            ],
            [
                'code' => 'invoices', 'name' => 'Facturation', 'route' => '/finance/invoices',
                'icon' => 'Receipt', 'order' => 31, 'menu' => 'crm_finance',
                'permissions' => ['finance.view_payments'],
            ],
            [
                'code' => 'ledger', 'name' => 'Comptabilité', 'route' => '/finance/ledger',
                'icon' => 'BookOpen', 'order' => 32, 'menu' => 'crm_finance',
                'permissions' => ['finance.manage'],
            ],
            [
                'code' => 'analytics_dashboard', 'name' => 'Tableaux de bord', 'route' => '/analytics',
                'icon' => 'BarChart3', 'order' => 40, 'menu' => 'analytics',
                'permissions' => ['analytics.view'],
            ],
            [
                'code' => 'analytics_assisted', 'name' => 'Achat assisté', 'route' => '/analytics/achat-assiste',
                'icon' => 'TrendingUp', 'order' => 41, 'menu' => 'analytics',
                'permissions' => ['analytics.view'],
            ],
            [
                'code' => 'analytics_shipments', 'name' => 'Expéditions', 'route' => '/analytics/expeditions',
                'icon' => 'Package', 'order' => 42, 'menu' => 'analytics',
                'permissions' => ['analytics.view'],
            ],
            [
                'code' => 'analytics_sav', 'name' => 'SAV', 'route' => '/analytics/sav',
                'icon' => 'HeadphonesIcon', 'order' => 43, 'menu' => 'analytics',
                'permissions' => ['analytics.view'],
            ],
            [
                'code' => 'analytics_finance', 'name' => 'Finance', 'route' => '/analytics/finance',
                'icon' => 'DollarSign', 'order' => 44, 'menu' => 'analytics',
                'permissions' => ['analytics.view'],
            ],
            [
                'code' => 'users_management', 'name' => 'Gestion utilisateurs', 'route' => '/users',
                'icon' => 'Users', 'order' => 50, 'menu' => 'users_module',
                'permissions' => ['rbac.manage_users'],
            ],
            [
                'code' => 'users_roles', 'name' => 'Rôles', 'route' => '/users/roles',
                'icon' => 'Shield', 'order' => 51, 'menu' => 'users_module',
                'permissions' => ['rbac.view_roles', 'rbac.manage_roles'],
            ],
            [
                'code' => 'users_navigation', 'name' => 'Navigation', 'route' => '/users/navigation',
                'icon' => 'Navigation', 'order' => 52, 'menu' => 'users_module',
                'permissions' => ['rbac.manage_menus'],
            ],
            [
                'code' => 'users_activity_log', 'name' => 'Journal d\'activité', 'route' => '/users/activity-log',
                'icon' => 'History', 'order' => 53, 'menu' => 'users_module',
                'permissions' => ['rbac.manage_users'],
            ],
            [
                'code' => 'settings', 'name' => 'Paramètres', 'route' => '/settings',
                'icon' => 'Settings', 'order' => 60, 'menu' => 'administration',
                'permissions' => ['admin.manage_settings'],
            ],
        ];

        foreach ($elements as $elData) {
            $menuId = null;
            if (! empty($elData['menu'])) {
                $menuId = $menus[$elData['menu']]->id ?? null;
            }

            $element = FrontendElement::updateOrCreate(
                ['code' => $elData['code']],
                [
                    'name' => $elData['name'],
                    'route' => $elData['route'],
                    'icon' => $elData['icon'] ?? null,
                    'order' => $elData['order'] ?? 0,
                    'menu_id' => $menuId,
                    'is_page' => true,
                    'is_active' => true,
                    'display_in_sidebar' => $elData['display_in_sidebar'] ?? true,
                ]
            );

            if (! empty($elData['permissions'])) {
                $permIds = Permission::whereIn('name', $elData['permissions'])->pluck('id');
                $element->permissions()->syncWithoutDetaching($permIds);
            }
        }

        // PRD : Colis Attendus retiré du menu · Suivi devis absorbé par le Tableau de suivi
        FrontendElement::where('code', 'shipment_notices')->delete();
        FrontendElement::where('route', '/purchase-orders/suivi')->delete();

        // ── 6. Assign role_id to existing users ──

        User::whereNull('role_id')->each(function ($user) {
            $spatieRole = $user->roles()->first();
            if ($spatieRole) {
                $user->update(['role_id' => $spatieRole->id]);
            }
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
