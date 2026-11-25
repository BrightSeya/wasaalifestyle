<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_calendar_connections', function (Blueprint $table) {
            $table->string('google_account_email')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('google_calendar_connections', function (Blueprint $table) {
            $table->dropColumn('google_account_email');
        });
    }
};
