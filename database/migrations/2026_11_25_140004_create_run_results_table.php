<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('runs')->cascadeOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->integer('iteration')->default(0);
            // Outcome: passed | failed | error.
            $table->string('status')->default('passed');
            // HTTP status the request returned (null: no response).
            $table->integer('status_code')->nullable();
            $table->integer('time_ms')->nullable();
            $table->integer('size')->nullable();
            $table->json('test_results')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_results');
    }
};
