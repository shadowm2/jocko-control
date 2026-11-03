<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    private const LABEL_LIMIT = 50;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * These two are labels for people to read. A device that sends a longer one
     * (the name of a long migration, say) must not lose its whole heartbeat, and
     * with it its command queue, over it: keep the first 50 characters.
     */
    protected function prepareForValidation(): void
    {
        foreach (['app_version', 'database_schema_version'] as $label) {
            if (is_string($this->input($label))) {
                $this->merge([$label => mb_substr($this->input($label), 0, self::LABEL_LIMIT)]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'app_version' => ['nullable', 'string', 'max:'.self::LABEL_LIMIT],
            'database_schema_version' => ['nullable', 'string', 'max:'.self::LABEL_LIMIT],
            'local_status' => ['nullable', 'array'],
            'local_status.state' => ['nullable', 'string', 'in:idle,syncing,error,maintenance'],
            'local_status.pending_events' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'local_status.oldest_pending_seconds' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'local_status.database_ok' => ['nullable', 'boolean'],
            'local_status.disk_free_mb' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'local_status.uptime_seconds' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'sync' => ['nullable', 'array'],
            'sync.status' => ['required_with:sync', 'string', 'in:success,failed'],
            'sync.error' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
