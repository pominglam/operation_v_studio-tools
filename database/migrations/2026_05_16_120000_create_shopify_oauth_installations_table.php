<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_oauth_installations', static function (Blueprint $table): void {
            $table->id();
            $table->string('shop_domain', 255)->unique();
            $table->longText('access_token');
            $table->text('scopes')->nullable();
            $table->timestamp('oauth_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_oauth_installations');
    }
};
