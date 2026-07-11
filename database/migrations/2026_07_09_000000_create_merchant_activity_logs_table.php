<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('merchant_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_merchant_user_id')->nullable()->constrained('merchant_users')->nullOnDelete();
            $table->foreignId('subject_merchant_user_id')->nullable()->constrained('merchant_users')->nullOnDelete();
            $table->string('section', 80);
            $table->string('type', 40)->default('action');
            $table->string('action', 120);
            $table->string('target_type', 120)->nullable();
            $table->string('target_id', 120)->nullable();
            $table->string('target_label', 255)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'created_at']);
            $table->index(['merchant_id', 'actor_merchant_user_id', 'created_at'], 'merchant_activity_actor_idx');
            $table->index(['merchant_id', 'subject_merchant_user_id', 'created_at'], 'merchant_activity_subject_idx');
            $table->index(['merchant_id', 'section', 'created_at']);
            $table->index(['merchant_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_activity_logs');
    }
};
