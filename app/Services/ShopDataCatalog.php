<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * What the read-only shop mirror knows about: which entities exist, which
 * fields of each are kept, and how a record is named. The definition lives in
 * config/shop_data.php.
 *
 * @phpstan-type EntityDefinition array{group: string, label: list<string>, columns: list<string>, fields: array<string, string>, children?: array<string, string>, parent?: string, amount?: string, date?: list<string>}
 */
class ShopDataCatalog
{
    private const IMPLICIT_FIELDS = [
        'id' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function has(string $entity): bool
    {
        return array_key_exists($entity, $this->definitions());
    }

    /**
     * @return list<string>
     */
    public function entities(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * Entities per group, in catalog order.
     *
     * @return array<string, list<string>>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->definitions() as $entity => $definition) {
            $groups[$definition['group']][] = $entity;
        }

        return $groups;
    }

    /**
     * @return array<string, string> field => type, implicit fields first
     */
    public function fields(string $entity): array
    {
        return self::IMPLICIT_FIELDS + $this->definition($entity)['fields'];
    }

    /**
     * @return list<string>
     */
    public function columns(string $entity): array
    {
        return $this->definition($entity)['columns'];
    }

    /**
     * Entities that point back at this one, as entity => foreign key field.
     *
     * @return array<string, string>
     */
    public function children(string $entity): array
    {
        return $this->definition($entity)['children'] ?? [];
    }

    /**
     * The foreign key kept in the indexed parent_id column, if any.
     */
    public function parentField(string $entity): ?string
    {
        return $this->definition($entity)['parent'] ?? null;
    }

    /**
     * The field kept in the indexed amount column, if any.
     */
    public function amountField(string $entity): ?string
    {
        return $this->definition($entity)['amount'] ?? null;
    }

    /**
     * The date a record counts for: the first configured date field it has,
     * else when it was created in the shop.
     *
     * @param  array<string, mixed>  $data
     */
    public function datedAt(string $entity, array $data): ?CarbonImmutable
    {
        foreach ([...($this->definition($entity)['date'] ?? []), 'created_at'] as $field) {
            $value = $data[$field] ?? null;

            if (is_string($value) && $value !== '') {
                return CarbonImmutable::parse($value)->utc();
            }
        }

        return null;
    }

    /**
     * The entity a field refers to, when it is a foreign key.
     */
    public function referencedEntity(string $entity, string $field): ?string
    {
        $type = $this->fields($entity)[$field] ?? null;

        if ($type === null || ! str_starts_with($type, 'ref:')) {
            return null;
        }

        $target = substr($type, 4);

        return $this->has($target) ? $target : null;
    }

    /**
     * Keep only the allowlisted fields, and only plain values: nothing nested
     * and nothing unknown is ever stored.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, bool|int|float|string|null>
     */
    public function only(string $entity, array $payload): array
    {
        $kept = [];

        foreach (array_keys($this->fields($entity)) as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];

            if ($value === null || is_scalar($value)) {
                $kept[$field] = $value;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function label(string $entity, array $data): string
    {
        $parts = [];

        foreach ($this->definition($entity)['label'] as $field) {
            $value = $data[$field] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                $parts[] = trim((string) $value);
            }
        }

        $label = implode(' ', $parts);

        return Str::limit($label !== '' ? $label : '#'.($data['id'] ?? ''), 255, '');
    }

    /**
     * @return EntityDefinition
     */
    private function definition(string $entity): array
    {
        return $this->definitions()[$entity] ?? throw new \InvalidArgumentException("Unknown shop data entity [{$entity}].");
    }

    /**
     * @return array<string, EntityDefinition>
     */
    private function definitions(): array
    {
        /** @var array<string, EntityDefinition> */
        return (array) config('shop_data.entities', []);
    }
}
