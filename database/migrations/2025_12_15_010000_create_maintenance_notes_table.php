<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 50);
            $table->text('body')->nullable();
            $table->timestamps();

            $table->unique('key', 'unique_maintenance_notes_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_notes');
    }
};
