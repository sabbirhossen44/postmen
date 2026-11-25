<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('histories', function (Blueprint $table) {
            // Full snapshot of the sent request (method, URL, params,
            // headers, auth with secrets blanked, body) so History can
            // restore a request in full instead of method + URL only.
            $table->json('request_snapshot')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('histories', function (Blueprint $table) {
            $table->dropColumn('request_snapshot');
        });
    }
};
