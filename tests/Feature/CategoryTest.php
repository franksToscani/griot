<?php

use App\Models\Category;
use Illuminate\Database\QueryException;

test('a category can be created with the factory', function () {
    $category = Category::factory()->create();

    expect($category->exists)->toBeTrue();
    $this->assertDatabaseHas('categories', ['slug' => $category->slug]);
});

test('category slugs are unique', function () {
    Category::factory()->create(['slug' => 'treccine']);

    expect(fn () => Category::factory()->create(['slug' => 'treccine']))
        ->toThrow(QueryException::class);
});
