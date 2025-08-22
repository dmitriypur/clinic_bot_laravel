<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    /**
     * Display a listing of applications.
     */
    public function index()
    {
        $applications = Application::with(['city', 'clinic', 'doctor'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);
            
        return ApplicationResource::collection($applications);
    }

    /**
     * Store a newly created application.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'city_id' => 'required|exists:cities,id',
            'clinic_id' => 'nullable|exists:clinics,id',
            'doctor_id' => 'nullable|exists:doctors,id',
            'full_name_parent' => 'nullable|string|max:255',
            'full_name' => 'required|string|max:255',
            'birth_date' => 'nullable|string|max:15',
            'phone' => 'required|string|max:25',
            'promo_code' => 'nullable|string|max:100',
            'tg_user_id' => 'nullable|integer',
            'tg_chat_id' => 'nullable|integer',
            'send_to_1c' => 'boolean',
        ]);

        // Генерируем ID как в Python версии (BigInteger)
        $validated['id'] = now()->format('YmdHis') . rand(1000, 9999);

        $application = Application::create($validated);
        
        // TODO: Отправка в 1C через очередь
        // TODO: Отправка уведомлений через вебхуки
        
        return new ApplicationResource($application->load(['city', 'clinic', 'doctor']));
    }

    /**
     * Display the specified application.
     */
    public function show(Application $application)
    {
        return new ApplicationResource($application->load(['city', 'clinic', 'doctor']));
    }

    /**
     * Update the specified application.
     */
    public function update(Request $request, Application $application)
    {
        $validated = $request->validate([
            'city_id' => 'sometimes|exists:cities,id',
            'clinic_id' => 'nullable|exists:clinics,id',
            'doctor_id' => 'nullable|exists:doctors,id',
            'full_name_parent' => 'nullable|string|max:255',
            'full_name' => 'sometimes|string|max:255',
            'birth_date' => 'nullable|string|max:15',
            'phone' => 'sometimes|string|max:25',
            'promo_code' => 'nullable|string|max:100',
            'send_to_1c' => 'boolean',
        ]);

        $application->update($validated);
        
        return new ApplicationResource($application->load(['city', 'clinic', 'doctor']));
    }

    /**
     * Remove the specified application.
     */
    public function destroy(Application $application)
    {
        $application->delete();
        
        return response()->json(null, 204);
    }
}