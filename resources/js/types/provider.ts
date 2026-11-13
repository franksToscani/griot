export type Category = {
    id: number;
    slug: string;
    name: string;
};

// A category of the home page (HomeCategoryResource): offered when something can be booked in it.
export type HomeCategory = Category & {
    offered: boolean;
};

// The orders of the provider list (ProviderIndexRequest::SORTS); null is the default, by name.
export type ProviderSort = 'name' | 'recent' | 'price_asc' | 'price_desc';

// Valid filters of the provider list and its order; null means "not filtered" (for sort: by
// name). max_price as "39.90"; with_photo "1" when on.
export type ProviderFilters = {
    category: string | null;
    city: string | null;
    q: string | null;
    max_price: string | null;
    with_photo: '1' | null;
    sort: ProviderSort | null;
};

// A filter of the list (ProviderIndexRequest::FILTERS): the order is not one.
export type ProviderFilterKey = Exclude<keyof ProviderFilters, 'sort'>;

// The one list of the filters, sent by the server: all of them, and those of the panel (counted
// by "Filtri (n)").
export type ProviderFilterKeys = {
    all: ProviderFilterKey[];
    panel: ProviderFilterKey[];
};

export type ProviderFilterOptions = {
    categories: Category[];
    cities: string[];
};

// The cover of a card (App\Http\Resources\ProviderCoverResource): decorative, no alternative
// text.
export type ProviderCover = {
    width: number;
    height: number;
    variants: PhotoVariant[];
};

// Shape of App\Http\Resources\ProviderResource.
export type ProviderListItem = {
    id: number;
    slug: string;
    name: string;
    city: string;
    avatar_url: string | null;
    categories: Category[];
    // The first photo of the work, or null.
    cover: ProviderCover | null;
    // The price of the cheapest active service, "30.00" (euros, without the options).
    min_price: string;
    // How many active services.
    services_count: number;
};

// Shape of App\Http\Resources\FeaturedProviderResource: a provider in "Professionisti in
// evidenza" on the home page, with its cover (decorative: no alternative text).
export type FeaturedProvider = {
    id: number;
    slug: string;
    name: string;
    city: string;
    categories: Category[];
    cover: ProviderCover | null;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

// Shape of a paginated Laravel resource collection.
export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: PaginationLink[];
    };
};

export type ServiceOption = {
    id: number;
    name: string;
    // Decimal string such as "20.00".
    extra_price: string;
    extra_duration: number;
};

export type Service = {
    id: number;
    name: string;
    description: string | null;
    // Decimal string such as "90.00".
    price: string;
    duration_min: number;
    duration_max: number;
    options: ServiceOption[];
};

export type ServiceGroup = {
    category: Category;
    services: Service[];
};

// One day from Monday (1) to Sunday (7); a closed day has no slots.
export type OpeningDay = {
    weekday: number;
    day: string;
    slots: { start: string; end: string }[];
};

// Shape of App\Http\Resources\ProviderProfileResource.
export type ProviderProfile = {
    slug: string;
    name: string;
    city: string;
    bio: string | null;
    avatar_url: string | null;
    contacts: {
        whatsapp: { number: string; url: string | null } | null;
        instagram: { username: string; url: string | null } | null;
    };
    service_groups: ServiceGroup[];
    opening_hours: OpeningDay[];
    photos: ProviderPhotoPublic[];
};

// Shape of App\Http\Resources\ProviderPhotoPublicResource: a photo of the work, in the mosaic.
export type ProviderPhotoPublic = {
    id: number;
    width: number;
    height: number;
    alt_text: string | null;
    // The sizes that exist, smallest first: the real width of each and its address.
    variants: PhotoVariant[];
};

export type PhotoVariant = {
    width: number;
    url: string;
};

// One item of "slots" in the free slots endpoint: the start in UTC and the provider's local time.
export type FreeSlot = {
    start: string;
    time: string;
};

// The first and last bookable days, as "YYYY-MM-DD" local dates of the provider.
export type BookingWindow = {
    first_day: string;
    last_day: string;
};
