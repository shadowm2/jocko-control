<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\ShopRecord;
use App\Services\ShopDataCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopRecord>
 */
class ShopRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $localId = fake()->unique()->numberBetween(1, 1_000_000);
        $name = fake()->words(2, true);

        return [
            'shop_id' => Shop::factory(),
            'entity' => 'brand',
            'local_id' => $localId,
            'aggregate_id' => fake()->uuid(),
            'label' => $name,
            'data' => ['id' => $localId, 'name' => $name, 'is_active' => true],
            'parent_id' => null,
            'amount' => null,
            'dated_at' => now()->subMinute(),
            'source_occurred_at' => now()->subMinute(),
            'removed_at' => null,
        ];
    }

    /**
     * A record of the given entity, with the indexed columns derived from its
     * data exactly as the projector does.
     *
     * @param  array<string, mixed>  $data
     */
    public function entity(string $entity, array $data, ?string $label = null): static
    {
        $catalog = app(ShopDataCatalog::class);
        $parent = $catalog->parentField($entity);
        $amount = $catalog->amountField($entity);

        return $this->state(fn (array $attributes): array => [
            'entity' => $entity,
            'local_id' => $data['id'] ?? $attributes['local_id'],
            'label' => $label,
            'parent_id' => $parent !== null && is_int($data[$parent] ?? null) ? $data[$parent] : null,
            'amount' => $amount !== null && is_numeric($data[$amount] ?? null) ? (string) $data[$amount] : null,
            'dated_at' => $catalog->datedAt($entity, $data) ?? now(),
            'data' => $data + ['id' => $attributes['local_id']],
        ]);
    }

    public function removed(): static
    {
        return $this->state(['removed_at' => now()]);
    }
}
