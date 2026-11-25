<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('theme', ['dark', 'light'])->default('dark');
            $table->enum('layout_density', ['comfortable', 'compact'])->default('comfortable');
            $table->enum('default_body_type', ['none', 'form-data', 'urlencoded', 'raw', 'binary', 'graphql'])->default('raw');
            $table->integer('default_timeout')->default(30);
            $table->integer('sidebar_width')->default(320);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
