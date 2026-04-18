<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_check', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique('unique_inventory_check_uuid');

            $table->string('name', 255)->nullable();
            $table->string('source', 255)->nullable();
            $table->string('uploaded_file_path', 1024)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_check');
    }
};
