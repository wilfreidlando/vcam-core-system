<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SendTextNotificationRequest extends FormRequest
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
            'text' => ['required', 'string'],
            'variables' => ['nullable', 'array'],
        ];
    }
}
