export type Category = {
    id: number;
    slug: string;
    name: string;
};

// Valid filters of the provider list; null means "not filtered".
export type ProviderFilters = {
    category: string | null;
    city: string | null;
};

export type ProviderFilterOptions = {
    categories: Category[];
    cities: string[];
};

// Shape of App\Http\Resources\ProviderResource.
export type ProviderListItem = {
    id: number;
    slug: string;
    name: string;
    city: string;
    avatar_url: string | null;
    categories: Category[];
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
