<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates only the batch envelope. Each event is validated individually by the
 * ingestion service so one malformed event does not discard the valid ones.
 */
class PushEventsRequest extends FormRequest
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
            'events' => ['required', 'array', 'min:1', 'max:'.(int) config('jocko.sync.max_events_per_batch')],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function events(): array
    {
        return array_values($this->input('events', []));
    }
}
