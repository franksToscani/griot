<?php

namespace App\Http\Requests\Concerns;

use App\Profile\ProfileDetails;
use Closure;

/**
 * The rules, the messages and the data of the provider profile fields, shared by the request
 * that creates the profile and the one that edits it. user_id, id, is_published and avatar_path
 * are never in the rules: sent in the body, they have no effect. The middleware has already
 * trimmed the strings and turned the empty ones into null.
 */
trait ProviderProfileRules
{
    /**
     * The Instagram username is saved without "@": only one is removed, so "@@x" stays "@x" and
     * is refused by the rule. A lone "@" becomes null.
     */
    protected function removeInstagramAt(): void
    {
        $instagram = $this->input('instagram');

        if (is_string($instagram)) {
            $username = preg_replace('/^@/', '', $instagram, 1);

            $this->merge(['instagram' => $username === '' ? null : $username]);
        }
    }

    /**
     * @return array<string, array<mixed>>
     */
    protected function providerProfileRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'city' => ['required', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:1000'],
            // bail: one message for a wrong number, even when several rules fail. International
            // format only: "+" or "00", then digits, spaces, brackets, dots and dashes.
            'whatsapp' => ['bail', 'nullable', 'string', 'max:30', 'regex:/^(\+|00)[0-9 ().\-]+$/', $this->whatsappDigits(...)],
            'instagram' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function providerProfileMessages(): array
    {
        return [
            'name.required' => __('provider.validation.name_required'),
            'name.max' => __('provider.validation.name_max'),
            'city.required' => __('provider.validation.city_required'),
            'city.max' => __('provider.validation.city_max'),
            'bio.max' => __('provider.validation.bio_max'),
            'whatsapp.string' => __('provider.validation.whatsapp_invalid'),
            'whatsapp.max' => __('provider.validation.whatsapp_invalid'),
            'whatsapp.regex' => __('provider.validation.whatsapp_invalid'),
            'instagram.string' => __('provider.validation.instagram_invalid'),
            'instagram.regex' => __('provider.validation.instagram_invalid'),
            'instagram.max' => __('provider.validation.instagram_max'),
        ];
    }

    /**
     * The profile from the validated data only; missing optional fields are null.
     */
    public function details(): ProfileDetails
    {
        $validated = $this->validated();
        $text = fn (string $key): ?string => isset($validated[$key]) && is_string($validated[$key]) ? $validated[$key] : null;

        return new ProfileDetails(
            name: (string) $text('name'),
            city: (string) $text('city'),
            bio: $text('bio'),
            whatsapp: $text('whatsapp'),
            instagram: $text('instagram'),
        );
    }

    /**
     * From 8 to 15 digits after the international prefix ("+" or "00").
     */
    private function whatsappDigits(string $attribute, mixed $value, Closure $fail): void
    {
        $number = is_string($value) ? preg_replace('/^(\+|00)/', '', $value, 1) : '';
        $digits = is_string($number) ? preg_match_all('/\d/', $number) : 0;

        if ($digits < 8 || $digits > 15) {
            $fail(__('provider.validation.whatsapp_invalid'));
        }
    }
}
