<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * When the provider was last published (issue #144): written when the profile goes from not
     * published to published, null while it is not published (App\Profile\PublishProvider). Only to
     * sort: "the most recently published first". The providers already published get updated_at,
     * the closest date there is. The index follows that query: is_published, then the date.
     */
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('is_published');

            $table->index(['is_published', 'published_at'], 'providers_published_index');
        });

        // The query builder, not the model: a migration must keep working when the model changes.
        DB::table('providers')->where('is_published', true)->update(['published_at' => DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropIndex('providers_published_index');
            $table->dropColumn('published_at');
        });
    }
};
