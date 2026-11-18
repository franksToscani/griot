<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // The nine categories (docs/home.md): the saved name is the Italian one, the shown name
        // comes from lang/{it,fr,en}.json (categories.{slug}). Never the slugs of before the
        // migration rename_and_merge_categories (barbiere, nails, ciglia, sopracciglia, couture,
        // ristorazione): run in production, this seeder would add them again.
        $categories = [
            'taglio-uomo' => 'Taglio uomo',
            'taglio-donna' => 'Taglio donna',
            'treccine' => 'Treccine',
            'locs' => 'Locs',
            'unghie' => 'Unghie',
            'make-up' => 'Make-up',
            'ciglia-sopracciglia' => 'Ciglia e sopracciglia',
            'moda-afro' => 'Moda afro',
            'food' => 'Food',
        ];

        foreach ($categories as $slug => $name) {
            Category::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
