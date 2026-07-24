<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table): void {
            $table->string('session_desired_state')->default('stopped')->after('session_name');
            $table->timestamp('start_requested_at')->nullable()->after('session_desired_state');
            $table->timestamp('stop_requested_at')->nullable()->after('start_requested_at');

            $table->index('session_desired_state');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table): void {
            $table->dropIndex(['session_desired_state']);
            $table->dropColumn([
                'session_desired_state',
                'start_requested_at',
                'stop_requested_at',
            ]);
        });
    }
};