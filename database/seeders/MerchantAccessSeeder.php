<?php

namespace Database\Seeders;

use App\Models\Capability;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MerchantAccessSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rolesData = config('rbac.merchant_roles', []);
        $capabilitiesData = config('rbac.merchant_capabilities', []);
        $matrix = config('rbac.merchant_role_capability_map', []);

        foreach ($rolesData as $role) {
            Role::query()->updateOrCreate(
                ['slug' => $role['slug']],
                [
                    'name' => $role['name'],
                    'description' => $role['description'] ?? null,
                ]);
        }

        foreach ($capabilitiesData as $capability) {
            Capability::query()->updateOrCreate(
                ['code' => $capability['code']],
                [
                    'name' => $capability['name'],
                    'description' => $capability['description'] ?? null,
                ]);
        }

        $rolesBySlug = Role::query()->get()->keyBy('slug');
        $capabilitiesByCode = Capability::query()->get()->keyBy('code');

        foreach ($matrix as $roleSlug => $capabilityCodes) {
            /** @var Role $role */
            $role = $rolesBySlug->get($roleSlug);

            if (!$role) {
                continue;
            }

            $capabilityIds = collect($capabilityCodes)
                ->map(fn(string $code) => $capabilitiesByCode->get($code)?->id)
                ->filter()
                ->values()
                ->all();

            $role->capabilities()->sync($capabilityIds);
        }
    }
}
