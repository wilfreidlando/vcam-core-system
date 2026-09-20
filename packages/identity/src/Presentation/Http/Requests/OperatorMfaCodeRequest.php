<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class OperatorMfaCodeRequest extends FormRequest
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
            'challenge_token' => ['required', 'string'],
            // min/max mirror the web form's equivalent field (security
            // review finding — this used to have no upper bound, an
            // oversized-payload gap on a public, throttle-only endpoint).
            'code' => ['required', 'string', 'min:6', 'max:8'],
        ];
    }
}
