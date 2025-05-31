<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'license_number' => $this->license_number,
            'status' => $this->status,
            'vehicle_id' => $this->vehicle_id,
            'created_at' => $this->created_at ? $this->created_at->addHours(7)->toDateTimeString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->addHours(7)->toDateTimeString() : null,
        ];
    }
}