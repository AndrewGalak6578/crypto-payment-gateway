<?php

namespace Database\Seeders;

use App\Models\MerchantUser;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BackfillMerchantUserRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $ownerRole = Role::query()->where('slug', 'merchant.owner')->firstOrFail();

        MerchantUser::query()
            ->whereNull('role_id')
            ->update(['role_id' => $ownerRole->id]);
    }
}
