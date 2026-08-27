<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->timestamp('rejected_at')->nullable()->after('estimated_arrival_at');
        });
    }

    public function down(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->dropColumn('rejected_at');
        });
    }
};
