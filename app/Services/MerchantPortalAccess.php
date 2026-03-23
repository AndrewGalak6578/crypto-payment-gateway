<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\MerchantUser;
use function Symfony\Component\Translation\t;

final class MerchantPortalAccess
{
    public function can(MerchantUser $merchantUser, string $capability): bool
    {
        $merchantUser->loadMissing('role.capabilities');

        if (!$merchantUser->role) {
            return false;
        }

        return $merchantUser->role->capabilities->contains('code', $capability);
    }

    public function canAny(MerchantUser $merchantUser, array $capabilityCodes): bool
    {
        $merchantUser->loadMissing('role.capabilities');

        if (!$merchantUser->role) {
            return false;
        }

        $granted = $merchantUser->role->capabilities
            ->pluck('code');

        foreach ($capabilityCodes as $code) {
            if ($granted->contains($code)) {
                return true;
            }
        }

        return false;
    }

    public function canAll(MerchantUser $merchantUser, array $capabilityCodes): bool
    {
        $merchantUser->loadMissing('role.capabilities');

        if (!$merchantUser->role) {
            return false;
        }

        $granted = $merchantUser->role->capabilities
            ->pluck('code');

        foreach ($capabilityCodes as $code) {
            if (!$granted->contains($code)) {
                return false;
            }
        }

        return true;
    }

    public function capabilitiesFor(MerchantUser $merchantUser): array
    {
        $merchantUser->loadMissing('role.capabilities');

        if (!$merchantUser->role) {
            return [];
        }

        return $merchantUser->role->capabilities
            ->pluck('code')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
