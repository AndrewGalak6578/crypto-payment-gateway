<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminUser;

final class AdminPortalAccess
{
    public function can(AdminUser $admin, string $capability): bool
    {
        $declared = config('rbac.admin_capabilities', []);
        $roleMap = config('rbac.admin_role_capability_map', []);

        if (
            ! is_array($declared)
            || ! in_array($capability, $declared, true)
            || ! is_array($roleMap)
        ) {
            return false;
        }

        $granted = $roleMap[$admin->role] ?? null;

        return is_array($granted) && in_array($capability, $granted, true);
    }

    /** @return array<int, string> */
    public function capabilitiesFor(AdminUser $admin): array
    {
        $declared = config('rbac.admin_capabilities', []);
        $roleMap = config('rbac.admin_role_capability_map', []);
        $granted = is_array($roleMap) ? ($roleMap[$admin->role] ?? []) : [];

        if (! is_array($declared) || ! is_array($granted)) {
            return [];
        }

        return array_values(array_filter(
            $declared,
            static fn (mixed $capability): bool => is_string($capability)
                && in_array($capability, $granted, true),
        ));
    }
}
