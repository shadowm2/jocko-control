<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\Shop;
use App\Services\AuditLogger;
use App\Services\ShopDataCatalog;
use App\Services\ShopDataExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShopDataExportController extends Controller
{
    /**
     * One entity of a shop's mirrored data as a CSV, for signed-in administrators (route
     * middleware). Who took what is written to the audit trail.
     */
    public function __invoke(Request $request, Shop $shop, string $entity, ShopDataCatalog $catalog, ShopDataExport $export, AuditLogger $audit): StreamedResponse
    {
        abort_unless($catalog->has($entity), 404);

        $search = (string) $request->query('search', '');
        $scope = (string) $request->query('scope', 'alive');

        $audit->record(AuditAction::ShopDataExported, $request->user(), $shop, metadata: ['entity' => $entity, 'search' => $search, 'scope' => $scope]);

        return $export->download($shop, $entity, $search, $scope);
    }
}
