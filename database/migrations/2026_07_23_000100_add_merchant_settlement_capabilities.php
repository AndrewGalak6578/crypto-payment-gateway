<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array{name: string, description: string}> */
    private array $capabilities = [
        'settlements.read' => [
            'name' => 'Settlements Read',
            'description' => 'View settlement activity, balances, wallets, and settlement rules',
        ],
        'settlements.write' => [
            'name' => 'Settlements Write',
            'description' => 'Manage merchant settlement rules',
        ],
    ];

    public function up(): void
    {
        $now = now('UTC');

        foreach ($this->capabilities as $code => $definition) {
            DB::table('capabilities')->insertOrIgnore([
                'code' => $code,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'updated_at' => $now,
                'created_at' => $now,
            ]);
        }

        $assignments = [
            'merchant.owner' => ['settlements.read', 'settlements.write'],
            'merchant.admin' => ['settlements.read', 'settlements.write'],
            'merchant.analyst' => ['settlements.read'],
            'merchant.viewer' => ['settlements.read'],
        ];

        foreach ($assignments as $roleSlug => $capabilityCodes) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if ($roleId === null) {
                continue;
            }

            foreach ($capabilityCodes as $code) {
                $capabilityId = DB::table('capabilities')->where('code', $code)->value('id');
                if ($capabilityId === null) {
                    continue;
                }

                DB::table('capability_role')->insertOrIgnore([
                    'role_id' => $roleId,
                    'capability_id' => $capabilityId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Irreversible data migration: deleting shared capabilities or grants is unsafe.
    }
};
