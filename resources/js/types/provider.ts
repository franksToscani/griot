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
