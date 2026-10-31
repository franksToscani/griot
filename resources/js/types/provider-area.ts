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

// The "publishing" prop of the dashboard: the codes of App\Profile\PublishingRequirements.
export type PublishingStatus = {
    can_publish: boolean;
    missing: string[];
};

// Shape of App\Http\Resources\ProviderServiceResource: a service in the provider's own area.
export type ProviderService = {
    id: number;
    name: string;
    description: string | null;
    category: { id: number; name: string };
    // Decimal string such as "25.50".
    price: string;
    duration_min: number;
    duration_max: number;
    is_active: boolean;
};

// The "bookings" prop of the service edit page: whether something blocks the deletion.
export type ServiceBookings = {
    has_bookings: boolean;
};

// Shape of App\Http\Resources\ProviderOptionResource: an option in the provider's own area.
export type ProviderOption = {
    id: number;
    name: string;
    // Decimal string such as "7.50".
    extra_price: string;
    extra_duration: number;
    // Chosen in at least one booking: it cannot be deleted.
    used: boolean;
    // False when hidden from new customers; the bookings that chose it keep it.
    is_active: boolean;
};

// Shape of App\Http\Resources\ProviderAvailabilityResource: a weekly time slot of the provider.
export type ProviderAvailability = {
    id: number;
    // ISO number: 1 = Monday ... 7 = Sunday.
    weekday: number;
    // Local Italian times, "HH:MM".
    start_time: string;
    end_time: string;
};

// One of the "weekdays" of the hours page: day is the key of its name in common.weekdays.*.
export type AvailabilityWeekday = {
    weekday: number;
    day: string;
};

// Shape of App\Http\Resources\ProviderBookingResource: a booking in the provider's own list.
export type ProviderBooking = {
    id: number;
    status: 'pending' | 'confirmed' | 'cancelled';
    // The provider's local day and times, already converted by the server.
    local_date: string;
    local_start: string;
    local_end: string;
    // Written by the customer: shown as plain text only.
    customer_name: string;
    customer_phone: string;
    customer_email: string | null;
    notes: string | null;
    service: { name: string };
    // Decimal strings such as "7.50".
    options: { name: string; extra_price: string }[];
    // Current price of the service plus the options chosen, in whole cents.
    price_cents: number;
    // Which actions the provider can take now, from the server's rules: the page never computes them.
    can_confirm: boolean;
    can_reject: boolean;
    can_cancel: boolean;
    // WhatsApp chat with the provider's answer already written, built by the server; null without
    // an answer from the provider or with a number that cannot be read. It holds the private link.
    whatsapp_url: string | null;
    // The provider's answer, from the server; null without one (pending, cancelled by the
    // customer, or cancelled before the outcome was saved).
    outcome: 'confirmed' | 'rejected' | 'cancelled' | null;
    // Written by the provider in the own agenda, born confirmed with no email to the customer.
    created_by_provider: boolean;
};

// The "filters" prop of the bookings page: the active ones, null when missing.
export type ProviderBookingFilters = {
    status: string | null;
    from: string | null;
    to: string | null;
};
