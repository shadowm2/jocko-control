<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CommandStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcknowledgeCommandRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(array_map(fn (CommandStatus $s) => $s->value, CommandStatus::acknowledgeable()))],
            'result' => ['nullable', 'array'],
            'error' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $result = $this->input('result');

                if (is_array($result) && strlen(json_encode($result)) > (int) config('jocko.commands.max_result_bytes')) {
                    $validator->errors()->add('result', 'The result is too large.');
                }
            },
        ];
    }

    public function commandStatus(): CommandStatus
    {
        return CommandStatus::from($this->validated('status'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resultPayload(): ?array
    {
        $result = $this->validated('result');
        $error = $this->validated('error');

        if ($error !== null) {
            $result = ($result ?? []) + ['error' => $error];
        }

        return $result;
    }
}
