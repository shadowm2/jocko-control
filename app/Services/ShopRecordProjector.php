<?php

namespace App\Services;

use App\Contracts\SyncEventHandler;
use App\Events\ShopChanged;
use App\Models\ShopRecord;
use App\Models\SyncEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Projects synchronization events into the read-only shop mirror.
 *
 * Every event carries the complete state of one record, so projecting is an
 * upsert: it is idempotent, and an event older than what the mirror already
 * holds is ignored, which makes late or replayed deliveries harmless.
 */
class ShopRecordProjector implements SyncEventHandler
{
    public function __construct(
        private readonly ShopDataCatalog $catalog,
        private readonly ShopChangeBroadcaster $broadcaster,
    ) {}

    public function supports(SyncEvent $event): bool
    {
        return $this->catalog->has($event->aggregate_type)
            && str_starts_with($event->event_type, $event->aggregate_type.'.');
    }

    public function handle(SyncEvent $event): void
    {
        $entity = $event->aggregate_type;
        $data = $this->catalog->only($entity, $event->payload);
        $localId = $data['id'] ?? null;

        if (! is_int($localId) || $localId < 1) {
            throw new InvalidArgumentException('The event payload has no valid record id.');
        }

        $attributes = [
            'aggregate_id' => $event->aggregate_id,
            'label' => $this->catalog->label($entity, $data),
            'parent_id' => $this->integerField($entity, $data, $this->catalog->parentField($entity)),
            'amount' => $this->amount($entity, $data),
            'dated_at' => $this->catalog->datedAt($entity, $data),
            'data' => $data,
            'source_occurred_at' => $event->occurred_at,
            'removed_at' => $this->removedAt($event, $data),
        ];

        $key = ['shop_id' => $event->shop_id, 'entity' => $entity, 'local_id' => $localId];

        try {
            $this->apply($key, $attributes);
        } catch (UniqueConstraintViolationException) {
            // Lost the race to create the row: it exists now, so update it.
            $this->apply($key, $attributes);
        }

        $this->broadcaster->changed($event->shop_id, ShopChanged::DATA);
    }

    /**
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $attributes
     */
    private function apply(array $key, array $attributes): void
    {
        DB::transaction(function () use ($key, $attributes): void {
            $record = ShopRecord::query()->where($key)->lockForUpdate()->first();

            if ($record === null) {
                ShopRecord::create($key + $attributes);

                return;
            }

            if ($attributes['source_occurred_at']->lt($record->source_occurred_at)) {
                return;
            }

            $record->update($attributes);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function integerField(string $entity, array $data, ?string $field): ?int
    {
        $value = $field === null ? null : ($data[$field] ?? null);

        return is_int($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function amount(string $entity, array $data): ?string
    {
        $field = $this->catalog->amountField($entity);
        $value = $field === null ? null : ($data[$field] ?? null);

        return is_numeric($value) ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function removedAt(SyncEvent $event, array $data): ?Carbon
    {
        if (is_string($data['deleted_at'] ?? null)) {
            return Carbon::parse($data['deleted_at'])->utc();
        }

        // A hard delete has no deleted_at to carry.
        return str_ends_with($event->event_type, '.deleted') ? $event->occurred_at : null;
    }
}
