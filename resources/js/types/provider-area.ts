// Shape of App\Http\Resources\ProviderAreaResource: the logged-in provider's own profile.
export type ProviderArea = {
    name: string;
    city: string;
    slug: string;
    is_published: boolean;
    avatar_url: string | null;
};

// The five profile fields a provider writes, as the forms send them.
export type ProviderProfileValues = {
    name: string;
    city: string;
    bio: string | null;
    whatsapp: string | null;
    instagram: string | null;
};

// Shape of App\Http\Resources\ProviderEditResource: the profile in the edit form.
export type ProviderEdit = ProviderProfileValues & {
    slug: string;
    is_published: boolean;
    avatar_url: string | null;
};
