<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;

/**
 * A category of the row "Categorie" of the home page (docs/home.md): the fields of
 * CategoryResource, plus whether something can be booked in it (Category::withOffered()). An
 * offered category is a link to the list; the others say "In arrivo".
 *
 * @mixin Category
 */
class HomeCategoryResource extends CategoryResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'offered' => (bool) $this->offered,
        ];
    }
}
