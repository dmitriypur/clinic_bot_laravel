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

        dd($cities);

        return ClinicResource::collection($cities);
    }

    public function byCity(City $city)
    {
        return ClinicResource::collection($city->clinics);
    }
}
