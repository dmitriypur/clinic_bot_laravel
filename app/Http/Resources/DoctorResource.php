<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoctorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->full_name,
            'experience' => $this->experience,
            'age' => $this->age,
            'photo_src' => $this->photo_src,
            'diploma_src' => $this->diploma_src,
            'status' => $this->status,
            'age_admission_from' => $this->age_admission_from,
            'age_admission_to' => $this->age_admission_to,
            'uuid' => $this->uuid,
            'review_link' => $this->review_link,
        ];
    }
}
