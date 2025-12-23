<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->string('verification_token', 64)->nullable()->after('metadata');
            $table->timestamp('verified_at')->nullable()->after('verification_token');

            $table->index('verification_token');
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropIndex(['verification_token']);
            $table->dropColumn(['verification_token', 'verified_at']);
        });
    }
};
