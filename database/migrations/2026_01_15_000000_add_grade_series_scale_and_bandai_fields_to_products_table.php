<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('grade', 32)->nullable()->after('type');
            $table->string('series', 255)->nullable()->after('grade');
            $table->string('scale', 16)->nullable()->after('series');
            $table->unsignedInteger('yen_price')->nullable()->after('scale');
            $table->date('bandai_launch_date')->nullable()->after('yen_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'grade',
                'series',
                'scale',
                'yen_price',
                'bandai_launch_date',
            ]);
        });
    }
};
