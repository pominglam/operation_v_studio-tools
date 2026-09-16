<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plamod_preorders', function (Blueprint $table): void {
            $table->timestamp('not_interested_at')->nullable()->after('dropped_at');
        });
    }

    public function down(): void
    {
        Schema::table('plamod_preorders', function (Blueprint $table): void {
            $table->dropColumn('not_interested_at');
        });
    }
};
