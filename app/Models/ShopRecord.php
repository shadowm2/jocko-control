<?php

namespace App\Models;

use Database\Factories\ShopRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of one business record of a local installation, projected
 * from its synchronization events. `data` holds only the allowlisted fields
 * of App\Services\ShopDataCatalog; `local_id` is the shop's own primary key.
 */
#[Fillable(['shop_id', 'entity', 'local_id', 'aggregate_id', 'label', 'parent_id', 'amount', 'dated_at', 'data', 'source_occurred_at', 'removed_at'])]
class ShopRecord extends Model
{
    /** @use HasFactory<ShopRecordFactory> */
    use HasFactory;

    /**
     * Keep millisecond precision for the event timestamps.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_id' => 'integer',
            'parent_id' => 'integer',
            'amount' => 'decimal:4',
            'dated_at' => 'datetime',
            'data' => 'array',
            'source_occurred_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    /**
     * @param  Builder<ShopRecord>  $query
     */
    public function scopeForShop(Builder $query, Shop|string $shop): void
    {
        $query->where('shop_id', $shop instanceof Shop ? $shop->getKey() : $shop);
    }

    /**
     * @param  Builder<ShopRecord>  $query
     */
    public function scopeOfEntity(Builder $query, string $entity): void
    {
        $query->where('entity', $entity);
    }

    /**
     * @param  Builder<ShopRecord>  $query
     */
    public function scopeAlive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /**
     * Records by their label (or exact local id, when the term is all digits).
     *
     * @param  Builder<ShopRecord>  $query
     */
    public function scopeMatching(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('label', 'like', '%'.addcslashes($term, '\\%_').'%');

            if (ctype_digit($term)) {
                $query->orWhere('local_id', (int) $term);
            }
        });
    }

    /**
     * 'alive' (not removed in the shop), 'removed', or anything else for all.
     *
     * @param  Builder<ShopRecord>  $query
     */
    public function scopeInScope(Builder $query, string $scope): void
    {
        match ($scope) {
            'alive' => $query->whereNull('removed_at'),
            'removed' => $query->whereNotNull('removed_at'),
            default => null,
        };
    }

    /**
     * A field of the mirrored record, or null when the shop never sent it.
     */
    public function field(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }
}
