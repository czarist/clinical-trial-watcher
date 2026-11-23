<?php

declare(strict_types=1);

namespace App\Enums;

enum PermissionName: string
{
    case ManageUsers = 'manage_users';
    case ManagePlans = 'manage_plans';
    case ManageRoles = 'manage_roles';
    case ManageImports = 'manage_imports';
    case ViewClinicalTrials = 'view_clinical_trials';
    case CreateAlerts = 'create_alerts';

    public function description(): string
    {
        return match ($this) {
            self::ManageUsers => 'Manage users',
            self::ManagePlans => 'Manage subscription plans',
            self::ManageRoles => 'Manage roles and permissions',
            self::ManageImports => 'View and start clinical trial imports',
            self::ViewClinicalTrials => 'View clinical trials',
            self::CreateAlerts => 'Create alerts',
        };
    }

    /**
     * @return list<self>
     */
    public static function forRegularUsers(): array
    {
        return [self::ViewClinicalTrials, self::CreateAlerts];
    }
}
