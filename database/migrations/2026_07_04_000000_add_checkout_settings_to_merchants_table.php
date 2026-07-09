<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            if (! Schema::hasColumn('merchants', 'checkout_display_name')) {
                $table->string('checkout_display_name')->nullable()->after('fee_percent');
            }

            if (! Schema::hasColumn('merchants', 'checkout_support_email')) {
                $table->string('checkout_support_email')->nullable()->after('checkout_display_name');
            }

            if (! Schema::hasColumn('merchants', 'checkout_brand_color')) {
                $table->string('checkout_brand_color', 20)->nullable()->after('checkout_support_email');
            }

            if (! Schema::hasColumn('merchants', 'checkout_expires_minutes')) {
                $table->unsignedSmallInteger('checkout_expires_minutes')->nullable()->after('checkout_brand_color');
            }

            if (! Schema::hasColumn('merchants', 'checkout_payer_can_choose_asset')) {
                $table->boolean('checkout_payer_can_choose_asset')->default(true)->after('checkout_expires_minutes');
            }

            if (! Schema::hasColumn('merchants', 'checkout_default_asset')) {
                $table->string('checkout_default_asset', 50)->nullable()->after('checkout_payer_can_choose_asset');
            }

            if (! Schema::hasColumn('merchants', 'checkout_allowed_assets')) {
                $table->json('checkout_allowed_assets')->nullable()->after('checkout_default_asset');
            }

            if (! Schema::hasColumn('merchants', 'checkout_success_url')) {
                $table->string('checkout_success_url')->nullable()->after('checkout_allowed_assets');
            }

            if (! Schema::hasColumn('merchants', 'checkout_cancel_url')) {
                $table->string('checkout_cancel_url')->nullable()->after('checkout_success_url');
            }

            if (! Schema::hasColumn('merchants', 'checkout_auto_redirect')) {
                $table->boolean('checkout_auto_redirect')->default(true)->after('checkout_cancel_url');
            }

            if (! Schema::hasColumn('merchants', 'checkout_redirect_delay_seconds')) {
                $table->unsignedTinyInteger('checkout_redirect_delay_seconds')->default(5)->after('checkout_auto_redirect');
            }

            if (! Schema::hasColumn('merchants', 'checkout_show_invoice_id')) {
                $table->boolean('checkout_show_invoice_id')->default(true)->after('checkout_redirect_delay_seconds');
            }

            if (! Schema::hasColumn('merchants', 'checkout_show_support_email')) {
                $table->boolean('checkout_show_support_email')->default(true)->after('checkout_show_invoice_id');
            }

            if (! Schema::hasColumn('merchants', 'checkout_partial_payment_policy')) {
                $table->string('checkout_partial_payment_policy', 40)->default('allow_top_up')->after('checkout_show_support_email');
            }

            if (! Schema::hasColumn('merchants', 'checkout_confirmation_display')) {
                $table->string('checkout_confirmation_display', 40)->default('simple')->after('checkout_partial_payment_policy');
            }

            if (! Schema::hasColumn('merchants', 'checkout_min_amount_usd')) {
                $table->decimal('checkout_min_amount_usd', 12, 2)->nullable()->after('checkout_confirmation_display');
            }

            if (! Schema::hasColumn('merchants', 'checkout_max_amount_usd')) {
                $table->decimal('checkout_max_amount_usd', 12, 2)->nullable()->after('checkout_min_amount_usd');
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter($this->columns(), fn (string $column): bool => Schema::hasColumn('merchants', $column)));

        if ($columns === []) {
            return;
        }

        Schema::table('merchants', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'checkout_display_name',
            'checkout_support_email',
            'checkout_brand_color',
            'checkout_expires_minutes',
            'checkout_payer_can_choose_asset',
            'checkout_default_asset',
            'checkout_allowed_assets',
            'checkout_success_url',
            'checkout_cancel_url',
            'checkout_auto_redirect',
            'checkout_redirect_delay_seconds',
            'checkout_show_invoice_id',
            'checkout_show_support_email',
            'checkout_partial_payment_policy',
            'checkout_confirmation_display',
            'checkout_min_amount_usd',
            'checkout_max_amount_usd',
        ];
    }
};
