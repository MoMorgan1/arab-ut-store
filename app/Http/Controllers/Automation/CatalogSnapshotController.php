<?php

namespace App\Http\Controllers\Automation;

use App\Actions\Catalog\SyncCatalogSnapshot;
use App\Exceptions\CatalogSnapshotReplay;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\CatalogSnapshotRequest;
use Illuminate\Http\JsonResponse;

final class CatalogSnapshotController extends Controller
{
    public function __invoke(
        CatalogSnapshotRequest $request,
        SyncCatalogSnapshot $syncCatalogSnapshot,
    ): JsonResponse {
        return $this->store($request, $syncCatalogSnapshot);
    }

    public function store(
        CatalogSnapshotRequest $request,
        SyncCatalogSnapshot $syncCatalogSnapshot,
        string $sourceKey = 'n8n-products',
        string $sourceName = 'n8n Products',
    ): JsonResponse {
        try {
            $summary = $syncCatalogSnapshot->execute(
                $request->validated(),
                hash('sha256', (string) $request->header('X-ArabUT-Signature')),
                $sourceKey,
                $sourceName,
            );
        } catch (CatalogSnapshotReplay) {
            return response()->json([
                'error' => [
                    'code' => 'catalog_snapshot_replayed',
                    'message' => 'The catalog snapshot has already been processed.',
                ],
            ], 409)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $summary], 201)
            ->header('Cache-Control', 'no-store');
    }
}
