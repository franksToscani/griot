<?php

namespace App\Models;

use App\Support\MediaDisk;
use Database\Factories\ProviderPhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo of the provider's work (issue #143, docs/gallery.md), kept as WebP in a few sizes on the
 * media disk: gallery/{name}-{side}.webp, side being the longest side in pixels.
 *
 * @property string $name random, never the name of the uploaded file
 * @property int $width width of the largest size made
 * @property int $height height of the largest size made
 */
class ProviderPhoto extends Model
{
    /** @use HasFactory<ProviderPhotoFactory> */
    use HasFactory;

    /**
     * Only the text a provider writes. name, the size, position and consent_at are always set by
     * explicit code, never by mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = ['alt_text'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'position' => 'integer',
            'consent_at' => 'immutable_datetime',
        ];
    }

    /**
     * The sizes made for this photo, smallest first: every configured size up to the photo's own
     * longest side, and always the smallest one (a photo is never enlarged).
     *
     * @return list<int>
     */
    public function sizes(): array
    {
        $configured = config()->array('booking.gallery.sizes');
        sort($configured);
        $longest = max($this->width, $this->height);

        $made = array_values(array_filter($configured, fn (int $side) => $side <= $longest));

        return $made === [] ? [(int) $configured[0]] : $made;
    }

    /**
     * The path of one size on the media disk.
     */
    public function pathFor(int $side): string
    {
        return "gallery/{$this->name}-{$side}.webp";
    }

    /**
     * The paths of every size made: what to remove from the disk with the photo.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(fn (int $side) => $this->pathFor($side), $this->sizes());
    }

    /**
     * The public address of one size.
     */
    public function urlFor(int $side): string
    {
        return MediaDisk::get()->url($this->pathFor($side));
    }

    /**
     * Get the provider the photo belongs to.
     *
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
