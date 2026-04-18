<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $needsUrl = ! Schema::hasColumn('product_external_assets', 'origin_url');
        $needsWidth = ! Schema::hasColumn('product_external_assets', 'origin_width');
        $needsHeight = ! Schema::hasColumn('product_external_assets', 'origin_height');

        if (! $needsUrl && ! $needsWidth && ! $needsHeight) {
            return;
        }

        Schema::table('product_external_assets', function (Blueprint $table) use ($needsUrl, $needsWidth, $needsHeight): void {
            if ($needsUrl) {
                $table->string('origin_url', 2000)->nullable()->after('size_bytes');
            }
            if ($needsWidth) {
                $table->unsignedInteger('origin_width')->nullable()->after($needsUrl ? 'origin_url' : 'size_bytes');
            }
            if ($needsHeight) {
                $table->unsignedInteger('origin_height')->nullable()->after($needsWidth ? 'origin_width' : ($needsUrl ? 'origin_url' : 'size_bytes'));
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('product_external_assets', 'origin_url')
            && ! Schema::hasColumn('product_external_assets', 'origin_width')
            && ! Schema::hasColumn('product_external_assets', 'origin_height')) {
            return;
        }

        Schema::table('product_external_assets', function (Blueprint $table): void {
            $cols = [];
            foreach (['origin_url', 'origin_width', 'origin_height'] as $c) {
                if (Schema::hasColumn('product_external_assets', $c)) {
                    $cols[] = $c;
                }
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
