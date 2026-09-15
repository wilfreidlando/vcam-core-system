<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SendFileNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', 'string'],
            'to_party_ref' => ['required', 'string', 'max:255'],
            'media_url' => ['required', 'string', 'max:2048'],
            'filename' => ['required', 'string', 'max:255'],
            'mimetype' => ['required', 'string', 'max:255'],
            'caption' => ['nullable', 'string'],
            'variables' => ['nullable', 'array'],
        ];
    }
}
