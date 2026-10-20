// Shape of App\Http\Resources\ProviderAreaResource: the logged-in provider's own profile.
export type ProviderArea = {
    name: string;
    city: string;
    slug: string;
    is_published: boolean;
    avatar_url: string | null;
};
