<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The column becomes required, but some bookings may already exist: it is added as
     * nullable, filled with a random token for each existing row, and only then made
     * required and unique.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->after('id');
        });

        // The query builder, not the model: a migration must keep working when the model changes.
        DB::table('bookings')->whereNull('public_token')->orderBy('id')->eachById(function (object $booking) {
            DB::table('bookings')->where('id', $booking->id)->update(['public_token' => Str::random(48)]);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable(false)->change();
            $table->unique('public_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
