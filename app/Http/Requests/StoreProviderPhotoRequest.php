<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * A photo of the provider's work (issue #143). As for the provider's photo, the rules look at the
 * content, not at the file name, and whatever passes is decoded and rewritten anyway. The
 * browser makes the photo smaller before sending it (resources/js/lib/resize-image.ts); these are
 * the limits of the server.
 */
class StoreProviderPhotoRequest extends FormRequest
{
    /**
     * The shortest a photo's longest side can be: below it the three sizes would be one.
     */
    public const MIN_LONGEST_SIDE = 800;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The controller checks ProviderPolicy::update, after sending a user without a provider
        // to the setup page.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // bail: one message per wrong file. max:2048 (KB, 2 MB) stays below PHP's
            // upload_max_filesize of 3M (docker/php.ini), on purpose: a file between 2 and 3 MB
            // gets this clear message; a bigger one does not even reach Laravel as a valid file
            // (see messages(), "uploaded"). JPEG, PNG and WebP only: no SVG (it can contain
            // scripts), no GIF.
            'photo' => [
                'bail',
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:max_width=4000,max_height=4000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $size = $value instanceof UploadedFile ? @getimagesize($value->getRealPath()) : false;

                    if ($size === false || max($size[0], $size[1]) < self::MIN_LONGEST_SIDE) {
                        $fail(__('provider.validation.photo_too_small', ['min' => self::MIN_LONGEST_SIDE]));
                    }
                },
            ],
            // The checkbox: only the moment it was ticked is kept (consent_at).
            'consent' => ['accepted'],
            'alt_text' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * Messages in the language of the page.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.required' => __('provider.validation.photo_required'),
            'photo.uploaded' => $this->tooBigForPhp()
                ? __('provider.validation.photo_max')
                : __('provider.validation.photo_invalid'),
            'photo.file' => __('provider.validation.photo_invalid'),
            'photo.mimes' => __('provider.validation.photo_invalid'),
            'photo.max' => __('provider.validation.photo_max'),
            'photo.dimensions' => __('provider.validation.photo_dimensions'),
            'consent.accepted' => __('provider.validation.photo_consent'),
            'alt_text.string' => __('provider.validation.photo_alt_max', ['max' => 150]),
            'alt_text.max' => __('provider.validation.photo_alt_max', ['max' => 150]),
        ];
    }

    /**
     * The validated photo.
     */
    public function photo(): UploadedFile
    {
        $file = $this->file('photo');

        return $file instanceof UploadedFile ? $file : throw new LogicException('A validated photo is missing.');
    }

    /**
     * The alternative text, null when empty.
     */
    public function altText(): ?string
    {
        $text = trim((string) $this->validated('alt_text'));

        return $text === '' ? null : $text;
    }

    /**
     * Whether PHP refused the file because of upload_max_filesize (or the form's MAX_FILE_SIZE).
     */
    private function tooBigForPhp(): bool
    {
        $file = $this->file('photo');

        return $file instanceof UploadedFile
            && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    }
}
