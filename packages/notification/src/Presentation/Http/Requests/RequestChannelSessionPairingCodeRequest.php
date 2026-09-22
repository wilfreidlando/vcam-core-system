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
            /**
             * Phone number of the WhatsApp device that will display
             * the pairing code (E.164 preferred, e.g. `+2376XXXXXXXX`).
             * Forwarded to WAHA as the device identifier for the
             * linking flow. The Core never persists this — the
             * provider (WAHA) is the single source of truth for the
             * session state.
             *
             * @example "+237690000000"
             */
            'phone_number' => ['required', 'string', 'max:32'],
        ];
    }
}
