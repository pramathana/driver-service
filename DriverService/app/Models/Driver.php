<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    protected $fillable = ['id', 'license_number', 'name', 'email', 'status', 'assigned_vehicle', 'created_at', 'updated_at'];
}
