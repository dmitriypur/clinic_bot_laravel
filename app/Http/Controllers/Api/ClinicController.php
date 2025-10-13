<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CityResource;
use App\Http\Resources\ClinicResource;
use App\Models\City;
use App\Models\Clinic;
use Illuminate\Http\Request;

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
                'error' => 'Clinic not found'
            ], 404);
        }

        return ClinicResource::collection($cities);
    }

    public function byCity(City $city)
    {
        return ClinicResource::collection($city->clinics);
    }

    public function branches(Request $request, Clinic $clinic)
    {
        $cityId = $request->input('city_id');

        $branchesQuery = $clinic->branches()->where('status', 1);
        if ($cityId) {
            $branchesQuery->where('city_id', $cityId);
        }

        $branches = $branchesQuery->orderBy('name')->get()->map(function ($branch) {
            return [
                'id' => $branch->id,
                'name' => $branch->name,
                'address' => $branch->address,
                'phone' => $branch->phone,
            ];
        });

        return response()->json([
            'data' => $branches,
        ]);
    }

}
