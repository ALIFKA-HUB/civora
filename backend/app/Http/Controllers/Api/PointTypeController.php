<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class PointTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $latest = DB::table('point_type_versions')
            ->select('point_type_id')
            ->selectRaw('MAX(version) AS version')
            ->groupBy('point_type_id');
        $data = DB::table('point_types')
            ->joinSub($latest, 'latest', fn ($join) => $join->on('latest.point_type_id', '=', 'point_types.id'))
            ->join('point_type_versions as versions', function ($join): void {
                $join->on('versions.point_type_id', '=', 'latest.point_type_id')
                    ->on('versions.version', '=', 'latest.version');
            })
            ->where('point_types.active', true)
            ->orderBy('point_types.code')
            ->get([
                'point_types.id', 'versions.id as version_id', 'point_types.category',
                'versions.name', 'versions.points', 'versions.description',
            ]);

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }
}
