<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DoctorResource;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Doctor;
use Illuminate\Http\Request;
use Carbon\Carbon;

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

    public function byClinic(Request $request, Clinic $clinic)
    {
        $age = $this->getAge($request);
        $doctors = $clinic->doctors()->where('age_admission_from', '<=', $age)->where('age_admission_to', '>=', $age)->get();
        return DoctorResource::collection($doctors);
    }

    public function byCity(Request $request, City $city)
    {
        $age = $this->getAge($request);
        $doctors = $city->allDoctors()->where('age_admission_from', '<=', $age)->where('age_admission_to', '>=', $age)->get();
        return DoctorResource::collection($doctors);
    }

    public function getAge($request): int
    {
        $birthDate = $request->input('birth_date');
        $age = Carbon::parse($birthDate)->age;
        return $age;
    }


}
