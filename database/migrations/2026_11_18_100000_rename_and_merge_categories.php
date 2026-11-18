<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The new categories of the site (docs/home.md, "Categorie"): four renamed, two merged, three new.
 * Only data: no column changes, so the code before this migration still works with it (a category
 * without its translation key shows its saved Italian name, Category::displayName()).
 *
 * - Renamed, on the same row, so every service keeps its category: barbiere → taglio-uomo, nails →
 *   unghie, couture → moda-afro, ristorazione → food, and ciglia → ciglia-sopracciglia.
 * - Merged: the services of sopracciglia move to ciglia-sopracciglia, then the row is deleted
 *   (services.category_id is restrictOnDelete, so the order matters).
 * - New, with no services ("In arrivo" until a published provider offers one): taglio-donna,
 *   locs, make-up. Only on a database that has categories already: on an empty one (a new
 *   install, the tests) CategorySeeder creates all nine.
 *
 * Idempotent, step by step by slug: run twice, the second run changes nothing. If the old and the
 * new slug both exist (CategorySeeder run before this migration), the services move to the new row
 * and the old one is deleted. The bookings point to the services, never to the categories: they
 * are not touched, and neither are the saved prices.
 *
 * In a transaction of its own (on PostgreSQL Laravel wraps each migration in one already): all or
 * nothing. Before running it in production, a copy of the database (docs/deploy.md, section 9).
 */
return new class extends Migration
{
    /**
     * Old slug => [new slug, new name, old name]. The names are the Italian ones saved in the
     * table; the shown ones are in lang/{it,fr,en}.json (categories.{slug}).
     *
     * @var array<string, array{string, string, string}>
     */
    private const RENAMES = [
        'barbiere' => ['taglio-uomo', 'Taglio uomo', 'Barbiere'],
        'nails' => ['unghie', 'Unghie', 'Nails'],
        'couture' => ['moda-afro', 'Moda afro', 'Couture'],
        'ristorazione' => ['food', 'Food', 'Ristorazione'],
        'ciglia' => ['ciglia-sopracciglia', 'Ciglia e sopracciglia', 'Ciglia'],
    ];

    /**
     * Merged into another category: old slug => [the slug it goes into, old name].
     *
     * @var array<string, array{string, string}>
     */
    private const MERGED = [
        'sopracciglia' => ['ciglia-sopracciglia', 'Sopracciglia'],
    ];

    /**
     * New categories: slug => name.
     *
     * @var array<string, string>
     */
    private const NEW = [
        'taglio-donna' => 'Taglio donna',
        'locs' => 'Locs',
        'make-up' => 'Make-up',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $seeded = DB::table('categories')->exists();

            foreach (self::RENAMES as $old => [$new, $name]) {
                $this->moveInto($old, $new, $name);
            }

            foreach (self::MERGED as $old => [$new]) {
                $this->moveInto($old, $new, self::RENAMES['ciglia'][1]);
            }

            if ($seeded) {
                $now = now();

                foreach (self::NEW as $slug => $name) {
                    DB::table('categories')->insertOrIgnore(['slug' => $slug, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        });
    }

    /**
     * Back to the seven categories of before, as far as it can go. IT CANNOT SPLIT the merged
     * services: which ones were "sopracciglia" is not known any more, so they all stay in
     * "ciglia". For a full way back: the copy of the database made before the migration.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            $seeded = DB::table('categories')->exists();

            // The new ones go only if nobody uses them: a service would block the delete.
            foreach (array_keys(self::NEW) as $slug) {
                DB::table('categories')
                    ->where('slug', $slug)
                    ->whereNotExists(fn ($services) => $services->select(DB::raw(1))->from('services')->whereColumn('services.category_id', 'categories.id'))
                    ->delete();
            }

            foreach (self::RENAMES as $old => [$new, , $oldName]) {
                if (DB::table('categories')->where('slug', $old)->doesntExist()) {
                    DB::table('categories')->where('slug', $new)->update(['slug' => $old, 'name' => $oldName, 'updated_at' => now()]);
                }
            }

            if ($seeded) {
                $now = now();

                foreach (self::MERGED as $old => [, $oldName]) {
                    DB::table('categories')->insertOrIgnore(['slug' => $old, 'name' => $oldName, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        });
    }

    /**
     * The category $old becomes $new: renamed on its row when $new does not exist yet; otherwise
     * its services move to $new and the row of $old is deleted. Nothing when $old does not exist
     * (already done, or never seeded).
     */
    private function moveInto(string $old, string $new, string $name): void
    {
        $from = DB::table('categories')->where('slug', $old)->value('id');

        if ($from === null) {
            return;
        }

        $to = DB::table('categories')->where('slug', $new)->value('id');

        if ($to === null) {
            DB::table('categories')->where('id', $from)->update(['slug' => $new, 'name' => $name, 'updated_at' => now()]);

            return;
        }

        // First the services, then the row: services.category_id is restrictOnDelete.
        DB::table('services')->where('category_id', $from)->update(['category_id' => $to, 'updated_at' => now()]);
        DB::table('categories')->where('id', $from)->delete();
    }
};
