<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * auth_type was an enum of 4 types; the new auth types (inherit,
     * digest, oauth2, aws) do not fit an enum, so it becomes a plain
     * string — same reason body_type becomes a string: 'graphql' was
     * already accepted by validation but rejected by the enum.
     */
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->string('auth_type')->default('none')->change();
            $table->string('body_type')->default('none')->change();
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->enum('auth_type', ['none', 'bearer', 'basic', 'api_key'])->default('none')->change();
            $table->enum('body_type', ['none', 'form-data', 'urlencoded', 'raw', 'binary'])->default('none')->change();
        });
    }
};
