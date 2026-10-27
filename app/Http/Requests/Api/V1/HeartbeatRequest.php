<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
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
            'app_version' => ['nullable', 'string', 'max:50'],
            'database_schema_version' => ['nullable', 'string', 'max:50'],
            'local_status' => ['nullable', 'array'],
            'local_status.state' => ['nullable', 'string', 'in:idle,syncing,error,maintenance'],
            'local_status.pending_events' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'local_status.database_ok' => ['nullable', 'boolean'],
            'local_status.disk_free_mb' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'local_status.uptime_seconds' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'sync' => ['nullable', 'array'],
            'sync.status' => ['required_with:sync', 'string', 'in:success,failed'],
            'sync.error' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
