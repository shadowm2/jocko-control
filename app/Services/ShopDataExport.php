<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\ShopRecord;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A CSV of one entity of a shop's mirrored data, as the list shows it: references by
 * name, enums in words, the same search and scope. It opens in Excel (UTF-8 with a
 * byte order mark) and no cell can start a formula.
 */
class ShopDataExport
{
    private const CHUNK = 500;

    public function __construct(private readonly ShopDataCatalog $catalog, private readonly ShopRecordReferences $references) {}

    public function download(Shop $shop, string $entity, string $search, string $scope): StreamedResponse
    {
        $fields = array_keys($this->catalog->fields($entity));
        $name = sprintf('%s-%s-%s.csv', $shop->slug, $entity, now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($shop, $entity, $search, $scope, $fields): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('ui.shop_data.record'), ...array_map(fn (string $field): string => $this->heading($field), $fields)], escape: '');

            ShopRecord::query()
                ->forShop($shop)
                ->ofEntity($entity)
                ->inScope($scope)
                ->matching($search)
                ->chunkById(self::CHUNK, function ($records) use ($out, $shop, $entity, $fields): void {
                    $references = $this->references->resolve($shop->getKey(), $entity, $records);

                    foreach ($records as $record) {
                        fputcsv($out, [
                            $this->safe((string) $record->label),
                            ...array_map(fn (string $field): string => $this->cell($entity, $field, $record, $references), $fields),
                        ], escape: '');
                    }
                });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function heading(string $field): string
    {
        return Lang::has('ui.shop_data.fields.'.$field) ? __('ui.shop_data.fields.'.$field) : $field;
    }

    /**
     * @param  array<string, array<int, ShopRecord>>  $references
     */
    private function cell(string $entity, string $field, ShopRecord $record, array $references): string
    {
        $value = $record->field($field);

        if ($value === null || $value === '') {
            return '';
        }

        $target = $this->catalog->referencedEntity($entity, $field);

        if ($target !== null) {
            $label = $references[$target][$value]->label ?? null;

            return $label === null ? '#'.$value : $this->safe($label);
        }

        $type = $this->catalog->fields($entity)[$field] ?? 'string';

        return match ($type) {
            'bool' => $value ? __('ui.common.yes') : __('ui.common.no'),
            'enum' => Lang::has($key = "ui.shop_data.values.{$entity}.{$field}.{$value}") ? __($key) : $this->safe((string) $value),
            'int', 'decimal' => (string) $value,
            default => $this->safe(is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE)),
        };
    }

    /**
     * A spreadsheet runs a cell that starts with = + - @ (or a tab or return) as a
     * formula, and the shops' text is not ours to trust: put a quote in front.
     */
    private function safe(string $text): string
    {
        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'".$text : $text;
    }
}
