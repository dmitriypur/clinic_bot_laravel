<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DoctorResource;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Doctor;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $doctors = Doctor::where('status', 1)->with(['applications', 'clinics', 'reviews'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);
        return DoctorResource::collection($doctors);
    }

    public function byClinic(Clinic $clinic)
    {
        return DoctorResource::collection($clinic->doctors);
    }

    public function byCity(City $city)
    {
        $doctors = $city->allDoctors()->get();
        return DoctorResource::collection($doctors);
    }
}
