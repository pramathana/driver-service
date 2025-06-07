<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'license_number' => $this->license_number,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'assigned_vehicle' => $this->assigned_vehicle,
            'created_at' => $this->created_at ? $this->created_at->addHours(7)->toDateTimeString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->addHours(7)->toDateTimeString() : null,
        ];
    }
}