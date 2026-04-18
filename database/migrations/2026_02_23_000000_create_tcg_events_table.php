<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tcg_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique('unique_tcg_events_uuid');

            $table->string('source', 50)->default('bandai_tcg_plus')->index('idx_tcg_events_source');
            $table->unsignedBigInteger('external_event_id')->unique('unique_tcg_events_external_event_id');

            $table->unsignedInteger('game_title_id')->index('idx_tcg_events_game_title_id');
            $table->string('game_title', 128)->nullable();

            $table->unsignedBigInteger('organizer_id')->nullable()->index('idx_tcg_events_organizer_id');
            $table->string('store_name', 255)->index('idx_tcg_events_store_name');
            $table->string('store_url', 512)->nullable();
            $table->string('store_sns_url', 512)->nullable();
            $table->string('phone_number', 32)->nullable();

            $table->string('country_code', 4)->nullable()->index('idx_tcg_events_country_code');
            $table->string('pref_code', 16)->nullable()->index('idx_tcg_events_pref_code');
            $table->string('city', 128)->nullable()->index('idx_tcg_events_city');
            $table->string('postcode', 16)->nullable();
            $table->string('street_address', 255)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            $table->unsignedBigInteger('event_series_id')->nullable()->index('idx_tcg_events_event_series_id');
            $table->string('event_name', 255)->index('idx_tcg_events_event_name');
            $table->text('excerpt')->nullable();

            $table->dateTime('start_datetime')->index('idx_tcg_events_start_datetime');
            $table->string('timezone', 64)->nullable();
            $table->dateTime('apply_start_datetime')->nullable();
            $table->dateTime('accepting_on_the_day_start_time')->nullable();
            $table->dateTime('accepting_on_the_day_end_time')->nullable();

            $table->unsignedInteger('status_id')->nullable()->index('idx_tcg_events_status_id');
            $table->string('status', 50)->nullable()->index('idx_tcg_events_status');

            $table->unsignedTinyInteger('entry_type')->nullable()->index('idx_tcg_events_entry_type');
            $table->string('lottery_method', 50)->nullable()->index('idx_tcg_events_lottery_method');

            $table->decimal('entry_fee', 12, 3)->nullable();
            $table->string('entry_fee_currency_code', 8)->nullable();

            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('applicants')->nullable();

            $table->json('game_format_ids')->nullable();
            $table->string('format', 128)->nullable()->index('idx_tcg_events_format');

            $table->json('raw_payload');
            $table->timestamp('fetched_at')->index('idx_tcg_events_fetched_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tcg_events');
    }
};
