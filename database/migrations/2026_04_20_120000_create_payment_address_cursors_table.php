<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_address_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('family', 32);
            $table->string('network_key', 64);
            $table->string('key_ref', 128);
            $table->unsignedBigInteger('next_index')->default(0);
            $table->timestamps();

            $table->unique(
                ['family', 'network_key', 'key_ref'],
                'payment_address_cursors_family_network_key_ref_unique'
            );
        });

        $existingRows = DB::table('payment_addresses')
            ->selectRaw('LOWER(network_key) as network_key, key_ref, MAX(derivation_index) as max_derivation_index')
            ->where('family', 'evm')
            ->whereNotNull('key_ref')
            ->whereNotNull('derivation_index')
            ->groupByRaw('LOWER(network_key), key_ref')
            ->get();

        if ($existingRows->isEmpty()) {
            return;
        }

        $now = now('UTC');
        $payload = [];

        foreach ($existingRows as $row) {
            $payload[] = [
                'family' => 'evm',
                'network_key' => (string)$row->network_key,
                'key_ref' => (string)$row->key_ref,
                'next_index' => ((int)$row->max_derivation_index) + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('payment_address_cursors')->insert($payload);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_address_cursors');
    }
};
