<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The customer's language: the one of the site when the request was sent, or the one the provider
     * chose for a booking written by hand. The emails to the customer and the WhatsApp text use it.
     * The bookings made before this column were all in Italian: "it".
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('locale', 5)->default('it')->after('created_by_provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
