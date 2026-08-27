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
            $table->string('merchandiser_order_proof_path', 512)->nullable()->after('product_visual_filename');
            $table->string('merchandiser_order_proof_mime', 128)->nullable()->after('merchandiser_order_proof_path');
            $table->string('merchandiser_order_proof_filename', 255)->nullable()->after('merchandiser_order_proof_mime');
        });
    }

    public function down(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'merchandiser_order_proof_path',
                'merchandiser_order_proof_mime',
                'merchandiser_order_proof_filename',
            ]);
        });
    }
};
