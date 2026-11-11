<?php

namespace App\Models;

use Database\Factories\ShopFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The preview image a shop sent for one of its `media` records. `media_id` is
 * the shop's own id; the bytes live on the private disk at `path`.
 */
#[Fillable(['shop_id', 'media_id', 'kind', 'sha256', 'mime_type', 'size', 'path'])]
class ShopFile extends Model
{
    /** @use HasFactory<ShopFileFactory> */
    use HasFactory;

    public const ORIGINAL = 'original';

    public const PREVIEW = 'preview';

    public function isPreview(): bool
    {
        return $this->kind !== self::ORIGINAL;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'media_id' => 'integer',
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
