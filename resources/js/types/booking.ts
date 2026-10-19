// Values of App\Enums\BookingStatus.
export type BookingStatus = 'pending' | 'confirmed' | 'cancelled';

// Shape of App\Http\Resources\BookingConfirmationResource.
export type BookingConfirmation = {
    status: BookingStatus;
    provider: {
        name: string;
        city: string;
        slug: string;
    };
    service: {
        name: string;
        // Decimal string such as "90.00".
        price: string;
    };
    options: {
        name: string;
        // Decimal string such as "20.00".
        extra_price: string;
    }[];
    // The provider's local date and times: "YYYY-MM-DD" and "HH:mm".
    local_date: string;
    local_start: string;
    local_end: string;
};
