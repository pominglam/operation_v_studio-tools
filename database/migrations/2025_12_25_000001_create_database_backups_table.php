<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_backups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('driver', 16);
            $table->string('filename', 255);
            $table->string('storage_path', 512);
            $table->string('description', 500)->default('');
            $table->string('created_by', 32)->default('manual'); // manual|system|cursor
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamps();

            $table->index(['created_at'], 'idx_database_backups_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_backups');
    }
};


