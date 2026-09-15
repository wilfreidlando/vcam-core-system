<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RequestChannelSessionPairingCodeRequest extends FormRequest
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
            'phone_number' => ['required', 'string', 'max:32'],
        ];
    }
}
