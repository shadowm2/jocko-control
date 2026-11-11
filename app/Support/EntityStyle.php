<?php

namespace App\Support;

/**
 * How a mirrored entity and its group look on the data overview: an icon for
 * each, a colour for each group. Written out in full (not built from a colour
 * name) because the CSS build only keeps class names it can read in the source.
 */
final class EntityStyle
{
    private const ICONS = [
        'order' => 'shopping-bag', 'order_item' => 'queue-list', 'payment' => 'banknotes', 'cash_close' => 'calculator',
        'user' => 'user', 'role' => 'shield-check', 'user_car' => 'key', 'car_company' => 'building-office', 'car' => 'truck', 'color' => 'swatch',
        'item' => 'cube', 'item_brand' => 'tag', 'brand' => 'bookmark', 'category' => 'squares-2x2', 'unit_group' => 'scale', 'unit' => 'hashtag',
        'warehouse' => 'building-storefront', 'warehouse_item' => 'archive-box', 'stock_movement' => 'arrows-right-left', 'media' => 'photo',
        'supplier' => 'user-group', 'purchase' => 'shopping-cart', 'purchase_item' => 'list-bullet',
        'country' => 'flag', 'province' => 'map', 'city' => 'map-pin',
    ];

    private const GROUP_ICONS = [
        'sales' => 'shopping-bag', 'people' => 'users', 'vehicles' => 'truck',
        'inventory' => 'cube', 'purchasing' => 'shopping-cart', 'geography' => 'globe-alt',
    ];

    /** @var array<string, array{chip: string, bar: string, ring: string, glow: string}> */
    private const PALETTES = [
        'sales' => ['chip' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300', 'bar' => 'bg-gradient-to-r from-emerald-400 to-emerald-600', 'ring' => 'hover:border-emerald-300 dark:hover:border-emerald-500/40', 'glow' => 'hover:shadow-emerald-500/10'],
        'people' => ['chip' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300', 'bar' => 'bg-gradient-to-r from-violet-400 to-violet-600', 'ring' => 'hover:border-violet-300 dark:hover:border-violet-500/40', 'glow' => 'hover:shadow-violet-500/10'],
        'vehicles' => ['chip' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300', 'bar' => 'bg-gradient-to-r from-sky-400 to-sky-600', 'ring' => 'hover:border-sky-300 dark:hover:border-sky-500/40', 'glow' => 'hover:shadow-sky-500/10'],
        'inventory' => ['chip' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', 'bar' => 'bg-gradient-to-r from-amber-400 to-amber-600', 'ring' => 'hover:border-amber-300 dark:hover:border-amber-500/40', 'glow' => 'hover:shadow-amber-500/10'],
        'purchasing' => ['chip' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300', 'bar' => 'bg-gradient-to-r from-rose-400 to-rose-600', 'ring' => 'hover:border-rose-300 dark:hover:border-rose-500/40', 'glow' => 'hover:shadow-rose-500/10'],
        'geography' => ['chip' => 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300', 'bar' => 'bg-gradient-to-r from-teal-400 to-teal-600', 'ring' => 'hover:border-teal-300 dark:hover:border-teal-500/40', 'glow' => 'hover:shadow-teal-500/10'],
    ];

    private const FALLBACK = ['chip' => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-500/15 dark:text-zinc-300', 'bar' => 'bg-gradient-to-r from-zinc-400 to-zinc-600', 'ring' => 'hover:border-zinc-300 dark:hover:border-zinc-500/40', 'glow' => 'hover:shadow-zinc-500/10'];

    public static function icon(string $entity): string
    {
        return self::ICONS[$entity] ?? 'circle-stack';
    }

    public static function groupIcon(string $group): string
    {
        return self::GROUP_ICONS[$group] ?? 'folder';
    }

    /**
     * @return array{chip: string, bar: string, ring: string, glow: string}
     */
    public static function palette(string $group): array
    {
        return self::PALETTES[$group] ?? self::FALLBACK;
    }
}
