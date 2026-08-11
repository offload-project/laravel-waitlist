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
            $table->string('mailing_list_driver', 50)->nullable()->after('invitation_id');
            $table->string('mailing_list_subscriber_id')->nullable()->after('mailing_list_driver');
            $table->timestamp('mailing_list_synced_at')->nullable()->after('mailing_list_subscriber_id');

            $table->index('mailing_list_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropIndex(['mailing_list_synced_at']);
            $table->dropColumn([
                'mailing_list_driver',
                'mailing_list_subscriber_id',
                'mailing_list_synced_at',
            ]);
        });
    }
};
