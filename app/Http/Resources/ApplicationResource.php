<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'city_id' => $this->city_id,
            'clinic_id' => $this->clinic_id,
            'branch_id' => $this->branch_id,
            'doctor_id' => $this->doctor_id,
            'cabinet_id' => $this->cabinet_id,
            'full_name_parent' => $this->full_name_parent,
            'full_name' => $this->full_name,
            'birth_date' => $this->birth_date,
            'appointment_datetime' => $this->appointment_datetime,
            'phone' => $this->phone,
            'promo_code' => $this->promo_code,
            'tg_user_id' => $this->tg_user_id,
            'tg_chat_id' => $this->tg_chat_id,
            'send_to_1c' => $this->send_to_1c,
            'integration_type' => $this->integration_type,
            'integration_status' => $this->integration_status,
            'external_appointment_id' => $this->external_appointment_id,
            'integration_payload' => $this->integration_payload,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
