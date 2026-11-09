// The logged-in user as the pages receive it (App\Http\Resources\AuthUserResource, issue #86):
// only these fields, nothing else of the model.
export type User = {
    name: string;
    email: string;
    email_verified_at: string | null;
};

// null for a visitor who is not logged in.
export type Auth = {
    user: User | null;
};
