<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CityResource;
use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CityController extends Controller
{
    /**
     * Display a listing of cities.
     */
    public function index(Request $request)
    {
        $query = City::where('status', 1); // Active cities only

        $perPage = (int) $request->get('size', 20);
        $usePagination = $request->has('page') || $request->has('size');

        if ($usePagination) {
            $cities = $query->orderBy('name')->paginate($perPage);

            if ($cities->isEmpty()) {
                return response()->json([
                    'error' => 'Cities not found',
                ], 404);
            }

            return CityResource::collection($cities);
        }

        $latestUpdate = City::query()->max('updated_at');
        $versionStamp = $latestUpdate ? (string) strtotime((string) $latestUpdate) : '0';
        $cacheKey = 'cities:index:' . md5($request->fullUrl() . '|' . $versionStamp);

        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached);
        }

        $cities = $query->orderBy('name')->get();

        if ($cities->isEmpty()) {
            return response()->json([
                'error' => 'Cities not found',
            ], 404);
        }

        $payload = CityResource::collection($cities)
            ->toResponse($request)
            ->getData(true);

        Cache::put($cacheKey, $payload, now()->addMinutes(5));

        return response()->json($payload);
    }

    /**
     * Store a newly created city.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'required|integer',
        ]);

        $city = City::create($validated);

        return new CityResource($city);
    }

    /**
     * Display the specified city.
     */
    public function show(City $city)
    {
        return new CityResource($city);
    }

    /**
     * Update the specified city.
     */
    public function update(Request $request, City $city)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|integer',
        ]);

        $city->update($validated);

        return new CityResource($city);
    }

    /**
     * Remove the specified city.
     */
    public function destroy(City $city)
    {
        $city->delete();

        return response()->json(null, 204);
    }
}
