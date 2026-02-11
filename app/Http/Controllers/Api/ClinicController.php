<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClinicResource;
use App\Models\City;
use App\Models\Clinic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ClinicController extends Controller
{
    /**
     * Display a listing of cities.
     */
    public function index(Request $request)
    {
        $query = Clinic::where('status', 1);

        $perPage = $request->get('size', 20);
        $cities = $query->orderBy('name')->with('branches')->paginate($perPage);

        if ($cities->isEmpty()) {
            return response()->json([
                'error' => 'Clinic not found',
            ], 404);
        }

        return ClinicResource::collection($cities);
    }

    public function byCity(Request $request, City $city)
    {
        $latestUpdate = $city->clinics()->max('clinics.updated_at');
        $versionStamp = $latestUpdate ? (string) strtotime((string) $latestUpdate) : '0';
        $cacheKey = 'clinics:by-city:'.$city->id.':'.md5($request->fullUrl().'|'.$versionStamp);

        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached);
        }

        $clinics = $city->clinics()
            ->where('clinics.status', 1)
            ->select('clinics.id', 'clinics.name')
            ->with(['branches' => function ($query) {
                $query->where('branches.status', 1)
                    ->select('branches.id', 'branches.clinic_id', 'branches.name');
            }])
            ->orderBy('clinics.name')
            ->get();

        $payload = ClinicResource::collection($clinics)
            ->toResponse($request)
            ->getData(true);

        Cache::put($cacheKey, $payload, now()->addMinutes(5));

        return response()->json($payload);
    }

    public function branches(Request $request, Clinic $clinic)
    {
        $cityId = $request->input('city_id');

        $branchesQuery = $clinic->branches()->where('status', 1);
        if ($cityId) {
            $branchesQuery->where('city_id', $cityId);
        }

        $latestUpdate = $branchesQuery->max('updated_at');
        $versionStamp = $latestUpdate ? (string) strtotime((string) $latestUpdate) : '0';
        $cacheKey = 'clinics:branches:'.$clinic->id.':'.($cityId ?: 'any').':'.$versionStamp;

        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached);
        }

        $branches = $branchesQuery
            ->select('id', 'name', 'address', 'phone')
            ->orderBy('name')
            ->get()
            ->map(function ($branch) {
                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'address' => $branch->address,
                    'phone' => $branch->phone,
                ];
            });

        $payload = [
            'data' => $branches,
        ];

        Cache::put($cacheKey, $payload, now()->addMinutes(5));

        return response()->json($payload);
    }
}
