<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * The provider's photo. The rules look at the content, not at the file name: "mimes" reads the
 * real type of the file, so a ".jpg" with text, SVG or PHP inside is refused, and a PNG named
 * ".jpg" is accepted. Whatever passes is decoded and rewritten by StoreProviderAvatar anyway.
 */
class StoreProviderAvatarRequest extends FormRequest
{
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
            // bail: one message per wrong file. max:1536 (KB, 1.5 MB) stays below PHP's
            // upload_max_filesize of 2M: a file between the two gets this clear message; a bigger
            // one does not even reach Laravel as a valid file (see messages(), "uploaded").
            // JPEG, PNG and WebP only: no SVG (it can contain scripts), no GIF.
            'avatar' => [
                'bail',
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:1536',
                'dimensions:min_width=200,min_height=200,max_width=4000,max_height=4000',
            ],
        ];
    }

    /**
     * Messages in Italian.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => __('provider.validation.avatar_required'),
            // "uploaded" is the failure Laravel adds when PHP refused the upload. Over the size
            // limits of PHP it is the weight message, otherwise a generic "not a valid photo".
            'avatar.uploaded' => $this->tooBigForPhp()
                ? __('provider.validation.avatar_max')
                : __('provider.validation.avatar_invalid'),
            'avatar.file' => __('provider.validation.avatar_invalid'),
            'avatar.mimes' => __('provider.validation.avatar_invalid'),
            'avatar.max' => __('provider.validation.avatar_max'),
            'avatar.dimensions' => __('provider.validation.avatar_dimensions'),
        ];
    }

    /**
     * The validated photo.
     */
    public function avatar(): UploadedFile
    {
        $file = $this->file('avatar');

        return $file instanceof UploadedFile ? $file : throw new LogicException('A validated avatar is missing.');
    }

    /**
     * Whether PHP refused the file because of upload_max_filesize (or the form's MAX_FILE_SIZE).
     * Without the field, or with an array, there is no error to read.
     */
    private function tooBigForPhp(): bool
    {
        $file = $this->file('avatar');

        return $file instanceof UploadedFile
            && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    }
}
