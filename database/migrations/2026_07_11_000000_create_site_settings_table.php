<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('site_name')->nullable();
            $table->string('brand_name')->nullable();
            $table->string('brand_logo')->nullable();
            $table->string('login_logo')->nullable();
            $table->string('favicon')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('whatsapp', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('primary_color')->nullable();
            $table->string('success_color')->nullable();
            $table->string('danger_color')->nullable();
            $table->boolean('is_maintenance_mode')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
