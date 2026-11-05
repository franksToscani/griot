<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The gallery of the provider's work (issue #143, docs/gallery.md). The files are
        // gallery/{name}-{side}.webp on the media disk; width and height are those of the largest
        // size made. At most config('booking.gallery.max_photos') per provider, kept by the code.
        Schema::create('provider_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40)->unique();
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->string('alt_text', 150)->nullable();
            $table->unsignedTinyInteger('position');
            // When the provider confirmed that the people in the photo agreed: nothing else.
            $table->timestamp('consent_at');
            $table->timestamps();

            // Not unique: swapping two positions would need a step in between.
            $table->index(['provider_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_photos');
    }
};
