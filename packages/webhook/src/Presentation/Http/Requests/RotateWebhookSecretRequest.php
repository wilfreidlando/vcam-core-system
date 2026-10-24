<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Http\Requests;

use Core\Webhook\Contracts\RotateWebhookSecretCommand;
use Illuminate\Foundation\Http\FormRequest;

final class RotateWebhookSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /**
             * Hours the previous secret keeps signing deliveries
             * (`X-Signature-Previous`), from 1 to 72.
             *
             * @example 24
             */
            'overlap_hours' => ['sometimes', 'integer', 'between:'.RotateWebhookSecretCommand::MIN_OVERLAP_HOURS.','.RotateWebhookSecretCommand::MAX_OVERLAP_HOURS],
        ];
    }

    public function overlapHours(): int
    {
        $value = $this->validated('overlap_hours', RotateWebhookSecretCommand::DEFAULT_OVERLAP_HOURS);

        return is_numeric($value) ? (int) $value : RotateWebhookSecretCommand::DEFAULT_OVERLAP_HOURS;
    }
}
