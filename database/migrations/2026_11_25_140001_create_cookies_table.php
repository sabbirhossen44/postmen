<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The first version of this migration failed part-way on MySQL
        // (1071: the unique key over domain + name + path was too long),
        // leaving the table behind without its unique index. Repair that
        // partial table in place instead of failing on "table exists".
        if (Schema::hasTable('cookies')) {
            $this->repairPartialTable();

            return;
        }

        Schema::create('cookies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('name');
            $table->text('value')->nullable();
            $table->string('path')->default('/');
            // SHA-256 of user_id|workspace_id|domain|name|path. A unique
            // index over the three 255-char columns is 3,076 bytes in
            // utf8mb4, over MySQL's 3,072 byte limit (error 1071), so the
            // identity is hashed to a fixed 64 chars instead.
            $table->char('key_hash', 64);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'workspace_id', 'key_hash']);
            $table->index(['user_id', 'workspace_id']);
        });
    }

    protected function repairPartialTable(): void
    {
        Schema::table('cookies', function (Blueprint $table) {
            if (! Schema::hasColumn('cookies', 'key_hash')) {
                $table->char('key_hash', 64)->nullable()->after('path');
            }
        });

        foreach (DB::table('cookies')->whereNull('key_hash')->get(['id', 'user_id', 'workspace_id', 'domain', 'name', 'path']) as $cookie) {
            DB::table('cookies')->where('id', $cookie->id)->update([
                'key_hash' => hash('sha256', implode('|', [$cookie->user_id, $cookie->workspace_id, $cookie->domain, $cookie->name, $cookie->path])),
            ]);
        }

        // Drop the old too-long unique key where it was created (e.g. SQLite).
        try {
            Schema::table('cookies', function (Blueprint $table) {
                $table->dropUnique('cookies_user_id_workspace_id_domain_name_path_unique');
            });
        } catch (\Throwable) {
            // It was never created: on MySQL the first migration failed
            // while adding it, so there is nothing to drop.
        }

        Schema::table('cookies', function (Blueprint $table) {
            $table->unique(['user_id', 'workspace_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cookies');
    }
};
