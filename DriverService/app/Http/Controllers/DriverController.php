<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Http\Resources\DriverResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Info(title="Driver Service API", version="1.0")
 */
class DriverController extends Controller
{
    /**
     * Create a new Guzzle HTTP client for the Auth Service
     * 
     * @return \GuzzleHttp\Client
     */
    private function getAuthServiceClient()
    {
        return new \GuzzleHttp\Client(['base_uri' => 'http://localhost:3001']);
    }

    /**
     * Create a new Guzzle HTTP client for the Vehicle Service
     * 
     * @return \GuzzleHttp\Client
     */
    private function getVehicleServiceClient()
    {
        return new \GuzzleHttp\Client(['base_uri' => 'http://localhost:8000']);
    }

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
     *     description="Creates a driver and registers a user account in the Auth Service with role 'pengemudi'",
     *     tags={"Drivers"},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="license_number", type="string", example="LIC123456", description="Will be used as the password for Auth Service"),
     *             @OA\Property(property="name", type="string", example="Joko Nawar"),
     *             @OA\Property(property="email", type="string", example="JagoNawar@yopmail.com"),
     *             @OA\Property(property="status", type="string", example="available")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Driver created and user registered in Auth Service"),
     *     @OA\Response(response=400, description="Invalid input"),
     *     @OA\Response(response=500, description="Failed to create driver or register with Auth Service")
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

            // Create driver record in database
            $driver = Driver::create($validated);
            
            // Generate username from name (remove spaces and add random digits)
            $username = strtolower(str_replace(' ', '', $validated['name'])) . rand(100, 999);
            
            // Register user with Auth Service
            $client = $this->getAuthServiceClient();
            $authResponse = $client->post('/auth/register', [
                'json' => [
                    'fullName' => $validated['name'],
                    'email' => $validated['email'],
                    'username' => $username,
                    'role' => 'pengemudi',
                    'password' => $validated['license_number'],
                    'confirmPassword' => $validated['license_number']
                ]
            ]);
            
            // Check auth service response
            $authResult = json_decode($authResponse->getBody()->getContents(), true);
            if ($authResponse->getStatusCode() !== 200) {
                // If auth registration fails, delete the driver record
                $driver->delete();
                return response()->json(['error' => 'Failed to register driver with Auth Service'], 500);
            }
            
            return new DriverResource($driver);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // If the auth service request fails
            if (isset($driver)) {
                $driver->delete();
            }
            $errorMessage = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
            return response()->json(['error' => 'Auth Service Error: ' . $errorMessage], 500);
        } catch (\Exception $e) {
            // If driver was created but something else failed, delete the driver
            if (isset($driver)) {
                $driver->delete();
            }
            return response()->json(['error' => 'Failed to create driver: ' . $e->getMessage()], 500);
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
            $client = $this->getVehicleServiceClient();
            $response = $client->get('/api/vehicles');
            $vehicles = json_decode($response->getBody()->getContents(), true);
            
            // Find the first available vehicle
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
            $success = $this->updateVehicleStatus($availableVehicle['id'], 'InUse');
            
            if (!$success) {
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
            $previousStatus = $driver->status;
            $previousVehicle = $driver->assigned_vehicle;
            
            $validated = $request->validate([
                'license_number' => 'required|string|unique:drivers,license_number,' . $id . '|max:50',
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:drivers,email,' . $id . '|max:255',
                'status' => 'nullable|string|in:available,on_duty,unavailable',
                'assigned_vehicle' => 'nullable|string|max:255',
            ]);

            // Check if we need to update vehicle status due to driver status change
            $statusChanged = isset($validated['status']) && $validated['status'] !== $previousStatus;
            $vehicleChanged = isset($validated['assigned_vehicle']) && $validated['assigned_vehicle'] !== $previousVehicle;
            
            $driver->update($validated);
            
            // If driver became available and had a vehicle, update vehicle status
            if ($statusChanged && $validated['status'] === 'available' && $previousVehicle) {
                $this->updateVehicleStatus($previousVehicle, 'Available');
            }
            
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

    /**
     * Update a vehicle's status in the Vehicle Service
     * 
     * @param string $vehicleId The ID of the vehicle to update
     * @param string $status The new status (Available, InUse, Maintenance)
     * @return bool Success or failure
     */
    private function updateVehicleStatus($vehicleId, $status)
    {
        try {
            $client = $this->getVehicleServiceClient();
            
            // First get the vehicle details
            $response = $client->get('/api/vehicles/' . $vehicleId);
            $vehicle = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($vehicle['data'])) {
                return false;
            }
            
            $vehicleData = $vehicle['data'];
            
            // Update vehicle status
            $updateResponse = $client->put('/api/vehicles/' . $vehicleId, [
                'json' => [
                    'type' => $vehicleData['type'],
                    'plate_number' => $vehicleData['plate_number'],
                    'status' => $status,
                ]
            ]);
            
            $updateResult = json_decode($updateResponse->getBody()->getContents(), true);
            return isset($updateResult['status']) && $updateResult['status'] === 'success';
        } catch (\Exception $e) {
            Log::error('Failed to update vehicle status: ' . $e->getMessage());
            return false;
        }
    }
}