<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDriversTable extends Migration
{
    public function up()
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('license_number')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('assigned_vehicle')->nullable();
            $table->string('status')->default('available'); // available, on_duty, unavailable
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('drivers');
    }
};