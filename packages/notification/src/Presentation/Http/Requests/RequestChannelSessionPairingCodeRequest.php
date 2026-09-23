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
             * The phone number of the WhatsApp device that will
             * display the pairing code, in international format
             * (E.164, e.g. `+2376XXXXXXXX`). The Core forwards
             * this value to the channel provider as the device
             * identifier for the linking flow. It is not stored
             * by the Core — the provider is the source of truth
             * for the session.
             *
             * @example "+237690000000"
             */
            'phone_number' => ['required', 'string', 'max:32'],
        ];
    }
}
