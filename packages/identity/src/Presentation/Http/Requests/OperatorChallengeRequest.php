<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class OperatorChallengeRequest extends FormRequest
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
        ];
    }
}
