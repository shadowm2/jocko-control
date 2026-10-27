<?php

namespace App\Services;

use App\Contracts\SyncEventHandler;
use App\Enums\AuditAction;
use App\Exceptions\ApiException;
use App\Models\ShopDevice;
use App\Models\SyncEvent;
use App\Support\CanonicalJson;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Durable, idempotent inbox for events pushed by local installations.
 *
 * Receiving an event only records it; nothing is applied to business tables
 * here. Handlers (see SyncEventHandler) can project events later.
 */
class SyncEventIngestionService
{
    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    private const IDENTIFIER = '/^[A-Za-z0-9_.:\-]+$/';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DeviceManagementService $devices,
    ) {}

    /**
     * Ingest a batch atomically: either every valid event is stored or, if an
     * unexpected error occurs, none is. Per-event problems (invalid fields,
     * conflicting replays) are reported in the result rather than thrown.
     *
     * @param  array<int, mixed>  $events
     */
    public function ingest(ShopDevice $device, array $events): SyncIngestionResult
    {
        $result = new SyncIngestionResult;
        $stored = [];

        try {
            DB::transaction(function () use ($device, $events, $result, &$stored): void {
                foreach (array_values($events) as $index => $event) {
                    $model = $this->ingestOne($device, $index, $event, $result);

                    if ($model !== null) {
                        $stored[] = $model;
                    }
                }
            });
        } catch (Throwable $e) {
            $this->handleUnexpectedFailure($device, $e);
        }

        $this->recordOutcome($device, $result);
        $this->dispatchToHandlers($device, $stored);

        return $result;
    }

    /**
     * @return SyncEvent|null The stored event when it was newly accepted.
     */
    private function ingestOne(ShopDevice $device, int $index, mixed $event, SyncIngestionResult $result): ?SyncEvent
    {
        $eventId = is_array($event) && is_string($event['event_id'] ?? null) && Validator::make($event, ['event_id' => 'uuid'])->passes()
            ? strtolower($event['event_id'])
            : null;

        $errors = $this->validationErrors($event);

        if ($errors !== null) {
            $result->rejected[] = [
                'index' => $index,
                'event_id' => $eventId,
                'code' => $errors['code'],
                'message' => $errors['message'],
                'details' => $errors['details'],
            ];

            return null;
        }

        $occurredAt = Carbon::parse($event['occurred_at'])->utc();

        $hash = CanonicalJson::hash([
            'event_type' => $event['event_type'],
            'aggregate_type' => $event['aggregate_type'],
            'aggregate_id' => strtolower($event['aggregate_id']),
            'base_version' => $event['base_version'] ?? null,
            'payload' => $event['payload'],
            'occurred_at' => $occurredAt->format('Y-m-d\TH:i:s.v\Z'),
        ]);

        $existing = SyncEvent::query()->where('event_id', $eventId)->first();

        if ($existing === null) {
            try {
                // A nested transaction is a savepoint, so a lost race on the
                // unique index does not poison the surrounding batch.
                $created = DB::transaction(fn (): SyncEvent => SyncEvent::create([
                    'event_id' => $eventId,
                    'shop_id' => $device->shop_id,
                    'device_id' => $device->getKey(),
                    'event_type' => $event['event_type'],
                    'aggregate_type' => $event['aggregate_type'],
                    'aggregate_id' => strtolower($event['aggregate_id']),
                    'base_version' => $event['base_version'] ?? null,
                    'payload' => $event['payload'],
                    'payload_hash' => $hash,
                    'occurred_at' => $occurredAt,
                    'received_at' => now(),
                ]));

                $result->accepted[] = ['index' => $index, 'event_id' => $eventId];

                return $created;
            } catch (UniqueConstraintViolationException) {
                $existing = SyncEvent::query()->where('event_id', $eventId)->firstOrFail();
            }
        }

        // Never reveal or compare against another shop's events.
        if ($existing->shop_id !== $device->shop_id) {
            $result->rejected[] = [
                'index' => $index,
                'event_id' => $eventId,
                'code' => 'event_id_conflict',
                'message' => 'This event_id cannot be used.',
            ];
            $this->audit->record(AuditAction::SyncEventConflict, shop: $device->shop_id, device: $device, metadata: ['event_id' => $eventId, 'reason' => 'event_id_belongs_to_another_shop']);

            return null;
        }

        if (hash_equals($existing->payload_hash, $hash)) {
            $result->alreadyAccepted[] = ['index' => $index, 'event_id' => $eventId];

            return null;
        }

        $result->rejected[] = [
            'index' => $index,
            'event_id' => $eventId,
            'code' => 'payload_mismatch',
            'message' => 'An event with this event_id was already received with different content.',
        ];
        $this->audit->record(AuditAction::SyncEventConflict, shop: $device->shop_id, device: $device, metadata: ['event_id' => $eventId, 'reason' => 'payload_mismatch']);

        return null;
    }

    /**
     * @return array{code: string, message: string, details: array<string, mixed>}|null
     */
    private function validationErrors(mixed $event): ?array
    {
        if (! is_array($event)) {
            return ['code' => 'validation_failed', 'message' => 'Each event must be an object.', 'details' => []];
        }

        $validator = Validator::make($event, [
            'event_id' => ['required', 'uuid'],
            'event_type' => ['required', 'string', 'max:100', 'regex:'.self::IDENTIFIER],
            'aggregate_type' => ['required', 'string', 'max:100', 'regex:'.self::IDENTIFIER],
            'aggregate_id' => ['required', 'uuid'],
            'base_version' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'payload' => ['present', 'array'],
            'occurred_at' => ['required', 'string', 'regex:'.self::ISO_8601, 'date', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
        ]);

        if ($validator->fails()) {
            return ['code' => 'validation_failed', 'message' => 'The event is malformed.', 'details' => $validator->errors()->toArray()];
        }

        $payload = $event['payload'];

        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > (int) config('jocko.sync.max_payload_bytes')) {
            return ['code' => 'payload_too_large', 'message' => 'The event payload exceeds the maximum size.', 'details' => []];
        }

        if (CanonicalJson::depth($payload) > (int) config('jocko.sync.max_payload_depth')) {
            return ['code' => 'payload_too_deep', 'message' => 'The event payload is nested too deeply.', 'details' => []];
        }

        return null;
    }

    private function recordOutcome(ShopDevice $device, SyncIngestionResult $result): void
    {
        if (! $result->hasRejections()) {
            $this->devices->markSyncSucceeded($device);

            return;
        }

        $codes = array_values(array_unique(array_column($result->rejected, 'code')));

        $this->devices->markSyncFailed($device, count($result->rejected).' event(s) rejected: '.implode(', ', $codes));

        $this->audit->record(AuditAction::SyncBatchRejected, shop: $device->shop_id, device: $device, metadata: [
            'accepted' => $result->acceptedCount(),
            'already_accepted' => count($result->alreadyAccepted),
            'rejected' => count($result->rejected),
            'samples' => array_map(
                fn (array $r): array => ['event_id' => $r['event_id'], 'code' => $r['code']],
                array_slice($result->rejected, 0, 10),
            ),
        ]);
    }

    /**
     * The exception is deliberately not chained: query exceptions embed their
     * bindings, which would copy event payloads into logs.
     */
    private function handleUnexpectedFailure(ShopDevice $device, Throwable $e): never
    {
        Log::error('Synchronization batch could not be stored.', [
            'device_id' => $device->getKey(),
            'shop_id' => $device->shop_id,
            'exception' => $e::class,
            'code' => $e->getCode(),
        ]);

        try {
            $this->devices->markSyncFailed($device, 'The server failed to store the submitted events.');
            $this->audit->record(AuditAction::SyncBatchError, shop: $device->shop_id, device: $device, metadata: ['exception' => $e::class]);
        } catch (Throwable) {
            // Bookkeeping must never mask the original failure.
        }

        throw ApiException::serverError('The events could not be stored. Retry the whole batch.');
    }

    /**
     * @param  array<int, SyncEvent>  $events
     */
    private function dispatchToHandlers(ShopDevice $device, array $events): void
    {
        $handlers = array_map(
            fn (string $class): SyncEventHandler => app($class),
            (array) config('jocko.sync.handlers', []),
        );

        foreach ($events as $event) {
            foreach ($handlers as $handler) {
                try {
                    if ($handler->supports($event)) {
                        $handler->handle($event);
                    }
                } catch (Throwable $e) {
                    Log::error('Synchronization event handler failed.', [
                        'event_id' => $event->event_id,
                        'device_id' => $device->getKey(),
                        'handler' => $handler::class,
                        'exception' => $e::class,
                    ]);
                }
            }
        }
    }
}
