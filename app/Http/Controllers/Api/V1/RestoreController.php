<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\ShopDevice;
use App\Models\ShopRecord;
use App\Services\AuditLogger;
use App\Services\ShopDataCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hands a shop's own mirrored records back to its device, so a lost
 * installation can be rebuilt. Only while an administrator has allowed it,
 * so a leaked device token cannot read the whole shop at any time.
 */
class RestoreController extends Controller
{
    private const PAGE_SIZE = 500;

    public function __construct(
        private readonly ShopDataCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * How many records of each entity can be restored.
     */
    public function summary(Request $request): JsonResponse
    {
        $device = $this->authorizedDevice($request);

        $counts = $this->restorable($device)
            ->selectRaw('entity, count(*) as total')
            ->groupBy('entity')
            ->pluck('total', 'entity');

        $this->audit->record(AuditAction::ShopRestoreStarted, null, $device->shop_id, $device);

        return response()->json(['data' => [
            'allowed_until' => $device->shop?->restoreAllowedUntil()?->toIso8601String(),
            'entities' => $counts->map(fn ($total): int => (int) $total),
        ]]);
    }

    /**
     * One page of an entity, by ascending local id: pass the returned `next` as `after`.
     */
    public function records(Request $request, string $entity): JsonResponse
    {
        $device = $this->authorizedDevice($request);

        abort_unless($this->catalog->has($entity), 404);

        $after = max(0, (int) $request->query('after', 0));

        $records = $this->restorable($device)
            ->where('entity', $entity)
            ->where('local_id', '>', $after)
            ->orderBy('local_id')
            ->limit(self::PAGE_SIZE + 1)
            ->get(['local_id', 'data']);

        $more = $records->count() > self::PAGE_SIZE;
        $page = $records->take(self::PAGE_SIZE);

        return response()->json(['data' => [
            'records' => $page->pluck('data')->values(),
            'next' => $more ? $page->last()?->local_id : null,
        ]]);
    }

    private function authorizedDevice(Request $request): ShopDevice
    {
        /** @var ShopDevice $device */
        $device = $request->user();
        $device->loadMissing('shop');

        abort_unless($device->shop?->restoreAllowed() ?? false, 403, 'Restore is not allowed for this shop. Ask an administrator to allow it.');

        return $device;
    }

    /**
     * Live records and soft-deleted ones (the shop keeps those); records the
     * shop removed for good are not brought back.
     *
     * @return Builder<ShopRecord>
     */
    private function restorable(ShopDevice $device)
    {
        return ShopRecord::query()->forShop($device->shop_id)
            ->where(function ($query): void {
                $query->whereNull('removed_at')->orWhereNotNull('data->deleted_at');
            });
    }
}
