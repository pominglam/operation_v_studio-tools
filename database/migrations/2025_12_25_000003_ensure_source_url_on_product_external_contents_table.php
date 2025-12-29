<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('product_external_contents', 'source_url')) {
            return;
        }

        Schema::table('product_external_contents', function (Blueprint $table): void {
            $table->text('source_url')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('product_external_contents', 'source_url')) {
            return;
        }

        Schema::table('product_external_contents', function (Blueprint $table): void {
            $table->dropColumn('source_url');
        });
    }
};


