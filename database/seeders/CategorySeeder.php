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
        $categories = [
            'treccine' => 'Treccine',
            'barbiere' => 'Barbiere',
            'nails' => 'Nails',
            'ciglia' => 'Ciglia',
            'sopracciglia' => 'Sopracciglia',
            'couture' => 'Couture',
            'ristorazione' => 'Ristorazione',
        ];

        foreach ($categories as $slug => $name) {
            Category::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
