<?php

namespace App\Services;

/**
 * Per-event outcome of ingesting one batch. Positions refer to the event's
 * zero-based index in the submitted batch.
 */
final class SyncIngestionResult
{
    /** @var array<int, array{index: int, event_id: string}> */
    public array $accepted = [];

    /** @var array<int, array{index: int, event_id: string}> */
    public array $alreadyAccepted = [];

    /** @var array<int, array{index: int, event_id: string|null, code: string, message: string, details?: array<string, mixed>}> */
    public array $rejected = [];

    public function acceptedCount(): int
    {
        return count($this->accepted);
    }

    public function hasRejections(): bool
    {
        return $this->rejected !== [];
    }

    /**
     * @return array{accepted: array<int, array<string, mixed>>, already_accepted: array<int, array<string, mixed>>, rejected: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'already_accepted' => $this->alreadyAccepted,
            'rejected' => $this->rejected,
        ];
    }
}
