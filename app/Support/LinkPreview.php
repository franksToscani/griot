<?php

namespace App\Support;

use App\Models\Provider;
use App\Models\Service;

/**
 * What a link to a page shows when it is shared (WhatsApp, Instagram, Facebook…): the <title>, the
 * description, Open Graph and Twitter tags, written by resources/views/partials/meta.blade.php in
 * the HTML of the first response. The crawlers do not run JavaScript, and Inertia has no SSR here:
 * the <Head> of the React pages never reaches them.
 *
 * One per request (AppServiceProvider). A page is private unless its controller says otherwise:
 * then it gets "noindex, nofollow" and no preview at all, so a new page cannot leak a name or a
 * photo by being forgotten. The public pages call publicPage() or forProvider().
 *
 * Every address is absolute and built on APP_URL, never on the Host header of the request.
 */
final class LinkPreview
{
    /**
     * The default image (public/og-default.png) and its size.
     */
    public const DEFAULT_IMAGE = 'og-default.png';

    public const DEFAULT_IMAGE_WIDTH = 1200;

    public const DEFAULT_IMAGE_HEIGHT = 630;

    /**
     * The longest description, in characters: about what the previews show before cutting.
     */
    public const DESCRIPTION_LENGTH = 160;

    /**
     * At most this many categories in the title of a provider's page.
     */
    public const TITLE_CATEGORIES = 2;

    /**
     * Open Graph locales of the languages of the site.
     */
    private const OG_LOCALES = ['it' => 'it_IT', 'fr' => 'fr_FR', 'en' => 'en_GB'];

    private bool $public = false;

    private ?string $title = null;

    private ?string $description = null;

    private ?string $image = null;

    private ?string $imageAlt = null;

    private string $type = 'website';

    /**
     * A public page: with a preview and indexable. Without a title, the name of the site; without
     * a description, the one of the site; the image is the default one.
     */
    public function publicPage(?string $title = null, ?string $description = null): self
    {
        $this->public = true;
        $this->title = $title;
        $this->description = $description;

        return $this;
    }

    /**
     * The page of a published provider: name, categories and city in the title, the bio (or a
     * sentence) as description, the photo if there is one.
     */
    public function forProvider(Provider $provider): self
    {
        $categories = $provider->services
            ->map(fn (Service $service) => $service->category->displayName())
            ->unique()
            ->values();

        $shown = $categories->take(self::TITLE_CATEGORIES)->implode(', ');

        if ($categories->count() > self::TITLE_CATEGORIES) {
            $shown .= '…';
        }

        $title = $shown === ''
            ? __('link_preview.provider.title_no_categories', ['name' => $provider->name, 'city' => $provider->city])
            : __('link_preview.provider.title', ['name' => $provider->name, 'categories' => $shown, 'city' => $provider->city]);

        $bio = self::excerpt((string) $provider->bio);

        $this->publicPage($title, $bio !== '' ? $bio : __('link_preview.provider.description', ['name' => $provider->name, 'city' => $provider->city]));
        $this->type = 'profile';

        $avatar = $provider->avatarUrl();

        if ($avatar !== null) {
            $this->image = $avatar;
            $this->imageAlt = __('provider.avatar.alt', ['name' => $provider->name]);
        }

        return $this;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    /**
     * The <title> of the HTML: "Title - Griot", or the name of the site alone.
     */
    public function title(): string
    {
        $site = (string) config('app.name', 'Griot');

        return $this->public && $this->title !== null ? "{$this->title} - {$site}" : $site;
    }

    /**
     * The tags of a public page, as name => content; empty for a private one.
     *
     * @return array<string, string>
     */
    public function tags(): array
    {
        if (! $this->public) {
            return [];
        }

        $site = (string) config('app.name', 'Griot');
        $title = $this->title ?? $site;
        $description = $this->description ?? __('link_preview.site.description');
        $url = self::absolute(request()->path());
        $own = $this->image === null;

        $tags = [
            'description' => $description,
            'canonical' => $url,
            'og:type' => $this->type,
            'og:site_name' => $site,
            'og:title' => $title,
            'og:description' => $description,
            'og:url' => $url,
            'og:locale' => self::OG_LOCALES[app()->getLocale()] ?? 'it_IT',
            'og:image' => $own ? self::absolute(self::DEFAULT_IMAGE) : (string) $this->image,
            'og:image:alt' => $own ? __('link_preview.image_alt') : (string) $this->imageAlt,
        ];

        // The size only when it is known: the default image. A provider's photo is up to 512px a
        // side, not always square.
        if ($own) {
            $tags['og:image:type'] = 'image/png';
            $tags['og:image:width'] = (string) self::DEFAULT_IMAGE_WIDTH;
            $tags['og:image:height'] = (string) self::DEFAULT_IMAGE_HEIGHT;
        }

        // A wide image for the default one, a small square for a photo.
        $tags['twitter:card'] = $own ? 'summary_large_image' : 'summary';
        $tags['twitter:title'] = $title;
        $tags['twitter:description'] = $description;
        $tags['twitter:image'] = $tags['og:image'];
        $tags['twitter:image:alt'] = $tags['og:image:alt'];

        return $tags;
    }

    /**
     * Plain text on one line, cut on a word with "…" past DESCRIPTION_LENGTH characters.
     */
    public static function excerpt(string $text): string
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        if (mb_strlen($plain) <= self::DESCRIPTION_LENGTH) {
            return $plain;
        }

        $cut = mb_substr($plain, 0, self::DESCRIPTION_LENGTH - 1);
        $space = mb_strrpos($cut, ' ');

        // On the last word, unless that throws away too much (a very long word).
        if ($space !== false && $space > self::DESCRIPTION_LENGTH / 2) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t,.;:-–—").'…';
    }

    /**
     * An absolute address on APP_URL.
     */
    private static function absolute(string $path): string
    {
        $path = trim($path, '/');

        return rtrim((string) config('app.url'), '/').($path === '' ? '' : '/'.$path);
    }
}
