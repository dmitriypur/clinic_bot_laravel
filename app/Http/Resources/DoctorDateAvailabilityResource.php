<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoctorDateAvailabilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'date' => $this['date'],
            'doctor_id' => $this['doctor_id'],
            'branch_id' => $this['branch_id'],
            'clinic_id' => $this['clinic_id'],
            'name' => $this['name'],
            'experience' => $this['experience'],
            'age' => $this['age'],
            'photo_src' => $this['photo_src'],
            'diploma_src' => $this['diploma_src'],
            'status' => $this['status'],
            'age_admission_from' => $this['age_admission_from'],
            'age_admission_to' => $this['age_admission_to'],
            'uuid' => $this['uuid'],
            'review_link' => $this['review_link'],
            'external_id' => $this['external_id'],
            'speciality' => $this['speciality'],
            'branch_name' => $this['branch_name'],
            'branch_address' => $this['branch_address'],
            'clinic_name' => $this['clinic_name'],
            'available_slots' => $this['available_slots'],
            'first_available_time' => $this['first_available_time'],
        ];
    }
}
