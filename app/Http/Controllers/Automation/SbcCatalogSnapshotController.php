<?php

namespace App\Http\Controllers\Automation;

use App\Actions\Catalog\SyncCatalogSnapshot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\SbcCatalogSnapshotRequest;
use Illuminate\Http\JsonResponse;

final class SbcCatalogSnapshotController extends Controller
{
    public function __invoke(
        SbcCatalogSnapshotRequest $request,
        SyncCatalogSnapshot $syncCatalogSnapshot,
        CatalogSnapshotController $catalogSnapshotController,
    ): JsonResponse {
        return $catalogSnapshotController->store(
            $request,
            $syncCatalogSnapshot,
            'n8n-sbc',
            'n8n SBC',
        );
    }
}
