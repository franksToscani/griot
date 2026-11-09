// Values of App\Enums\BookingStatus.
export type BookingStatus = 'pending' | 'confirmed' | 'cancelled';

// Shape of App\Http\Resources\BookingConfirmationResource.
export type BookingConfirmation = {
    status: BookingStatus;
    provider: {
        name: string;
        city: string;
        slug: string;
        // https://wa.me/<digits>, only for a pending or confirmed booking with a valid number.
        whatsapp_url: string | null;
    };
    // The prices saved when the booking was made, in whole cents (issue #97).
    service: {
        name: string;
        price_cents: number;
    };
    options: {
        name: string;
        extra_price_cents: number;
    }[];
    // The service plus the options, computed by the server.
    total_cents: number;
    // The provider's local date and times: "YYYY-MM-DD" and "HH:mm".
    local_date: string;
    local_start: string;
    local_end: string;
    // The provider's message, only on a cancelled booking; plain text.
    cancellation_reason: string | null;
};
