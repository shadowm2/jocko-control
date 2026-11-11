<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ShopDevice;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'media_id' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            // A downscaled preview, unless an administrator switched this shop to keep originals.
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.$this->maxKilobytes()],
        ];
    }

    private function maxKilobytes(): int
    {
        /** @var ShopDevice|null $device */
        $device = $this->user();
        $keepsOriginals = $device?->shop?->keepsOriginalPictures() ?? true;

        return (int) config($keepsOriginals ? 'jocko.files.max_original_kilobytes' : 'jocko.files.max_kilobytes');
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');

                if ($validator->errors()->isEmpty() && $file instanceof UploadedFile && ! hash_equals((string) $this->input('sha256'), (string) hash_file('sha256', $file->getRealPath()))) {
                    $validator->errors()->add('sha256', 'The checksum does not match the file.');
                }
            },
        ];
    }
}
