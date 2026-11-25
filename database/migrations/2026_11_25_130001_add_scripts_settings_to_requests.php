<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            // Scripts tab (pre-request + post-response/test scripts).
            // Stored only for now — execution is the Phase 5 sandbox.
            $table->json('scripts')->nullable()->after('body');
            // Settings tab: timeout, max redirects, follow redirects.
            $table->json('settings')->nullable()->after('scripts');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn(['scripts', 'settings']);
        });
    }
};
