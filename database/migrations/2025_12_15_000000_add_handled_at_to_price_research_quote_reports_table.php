<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_research_quote_reports', function (Blueprint $table): void {
            $table->timestamp('handled_at')->nullable()->after('note');
            $table->index(['handled_at', 'created_at'], 'idx_pr_quote_reports_handled_created');
        });
    }

    public function down(): void
    {
        Schema::table('price_research_quote_reports', function (Blueprint $table): void {
            $table->dropIndex('idx_pr_quote_reports_handled_created');
            $table->dropColumn('handled_at');
        });
    }
};
