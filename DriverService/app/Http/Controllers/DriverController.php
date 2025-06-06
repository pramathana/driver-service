<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Http\Resources\DriverResource;
use Illuminate\Http\Request;

/**
 * @OA\Info(title="Driver Service API", version="1.0")
 */
class DriverController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/drivers",
     *     summary="List all drivers",
     *     tags={"Drivers"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
    public function index()
    {
        try {
            return DriverResource::collection(Driver::all());
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch drivers'], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/drivers",
     *     summary="Create a new driver",
     *     tags={"Drivers"},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="license_number", type="string", example="LIC123456"),
     *             @OA\Property(property="name", type="string", example="Joko Nawar"),
     *             @OA\Property(property="email", type="string", example="JagoNawar@yopmail.com"),
     *             @OA\Property(property="status", type="string", example="available")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Driver created"),
     *     @OA\Response(response=400, description="Invalid input")
     * )
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'license_number' => 'required|string|unique:drivers|max:50',
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:drivers|max:255',
                'status' => 'nullable|string|in:available,on_duty,unavailable',
            ]);

            $driver = Driver::create($validated);
            return new DriverResource($driver);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to create driver'], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/drivers/assign",
     *     summary="Assign an available driver to an available vehicle",
     *     description="Automatically finds an available vehicle from the Vehicle Service, assigns it to the driver, and updates the vehicle status to InUse",
     *     tags={"Drivers"},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="driver_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Driver assigned to vehicle"),
     *     @OA\Response(response=200, description="Driver assigned to vehicle successfully"),
     *     @OA\Response(response=400, description="Invalid driver or no available vehicles"),
     *     @OA\Response(response=404, description="Driver not found"),
     *     @OA\Response(response=500, description="Failed to update vehicle status")
     * )
     */
    public function assign(Request $request)
    {
        try {
            $validated = $request->validate([
                'driver_id' => 'required|integer',
            ]);

            $driver = Driver::find($validated['driver_id']);
            if (!$driver) {
                return response()->json(['error' => 'Driver not found'], 404);
            }
            if ($driver->status !== 'available') {
                return response()->json(['error' => 'Driver is not available'], 400);
            }

            // Get available vehicles from Vehicle Service
            $client = new \GuzzleHttp\Client(['base_uri' => 'http://localhost:8000']); // Change base_uri to your vehicle service host
            $response = $client->get('/api/vehicles');
            $vehicles = json_decode($response->getBody()->getContents(), true);
            $availableVehicle = null;
            if (isset($vehicles['data']) && is_array($vehicles['data'])) {
                foreach ($vehicles['data'] as $vehicle) {
                    if (isset($vehicle['status']) && strtolower($vehicle['status']) === 'available') {
                        $availableVehicle = $vehicle;
                        break;
                    }
                }
            }
            if (!$availableVehicle) {
                return response()->json(['error' => 'No available vehicle found'], 400);
            }

            // Assign vehicle to driver
            $driver->update([
                'assigned_vehicle' => $availableVehicle['id'],
                'status' => 'on_duty'
            ]);

            // Update vehicle status to InUse in Vehicle Service
            $updateResponse = $client->put('/api/vehicles/' . $availableVehicle['id'], [
                'json' => [
                    'type' => $availableVehicle['type'],
                    'plate_number' => $availableVehicle['plate_number'],
                    'status' => 'InUse',
                ]
            ]);
            
            // Check if vehicle was updated successfully
            $updateResult = json_decode($updateResponse->getBody()->getContents(), true);
            if (!isset($updateResult['status']) || $updateResult['status'] !== 'success') {
                // Revert driver changes if vehicle update fails
                $driver->update([
                    'assigned_vehicle' => null,
                    'status' => 'available'
                ]);
                return response()->json(['error' => 'Failed to update vehicle status'], 500);
            }

            return new DriverResource($driver);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to assign driver: ' . $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/drivers/{id}",
     *     summary="Get driver by ID",
     *     tags={"Drivers"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Successful operation"),
     *     @OA\Response(response=404, description="Driver not found")
     * )
     */
    public function show($id)
    {
        try {
            $driver = Driver::findOrFail($id);
            return new DriverResource($driver);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Driver not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch driver'], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/drivers/{id}",
     *     summary="Update a driver",
     *     tags={"Drivers"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="license_number", type="string", example="LIC123456"),
     *             @OA\Property(property="name", type="string", example="Joko Nawar"),
     *             @OA\Property(property="email", type="string", example="JagoNawar@yopmail.com"),
     *             @OA\Property(property="status", type="string", example="available"),
     *             @OA\Property(property="assigned_vehicle", type="string", example="VEH123", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Driver updated"),
     *     @OA\Response(response=400, description="Invalid input"),
     *     @OA\Response(response=404, description="Driver not found")
     * )
     */
    public function update(Request $request, $id)
    {
        try {
            $driver = Driver::findOrFail($id);
            $validated = $request->validate([
                'license_number' => 'required|string|unique:drivers,license_number,' . $id . '|max:50',
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:drivers,email,' . $id . '|max:255',
                'status' => 'nullable|string|in:available,on_duty,unavailable',
                'assigned_vehicle' => 'nullable|string|max:255',
            ]);

            $driver->update($validated);
            return new DriverResource($driver);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Driver not found'], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update driver'], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/drivers/{id}",
     *     summary="Delete a driver",
     *     tags={"Drivers"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=204, description="Driver deleted"),
     *     @OA\Response(response=404, description="Driver not found")
     * )
     */
    public function destroy($id)
    {
        try {
            $driver = Driver::findOrFail($id);
            $driver->delete();
            return response()->json(null, 204);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Driver not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete driver'], 500);
        }
    }
}