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
            'doctor_id' => $this->doctor_id,
            'full_name_parent' => $this->full_name_parent,
            'full_name' => $this->full_name,
            'birth_date' => $this->birth_date,
            'phone' => $this->phone,
            'promo_code' => $this->promo_code,
            'tg_user_id' => $this->tg_user_id,
            'tg_chat_id' => $this->tg_chat_id,
            'send_to_1c' => $this->send_to_1c,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}