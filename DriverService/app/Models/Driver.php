<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    protected $fillable = ['id','user_id', 'license_number', 'status', 'vehicle_id','created_at','updated_at'];
}
