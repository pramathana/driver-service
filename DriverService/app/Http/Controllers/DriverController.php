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
     *             @OA\Property(property="user_id", type="integer", example=1),
     *             @OA\Property(property="license_number", type="string", example="LIC123456"),
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
                'user_id' => 'required|integer|unique:drivers',
                'license_number' => 'required|string|unique:drivers|max:50',
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
     *     summary="Assign a driver to a vehicle",
     *     tags={"Drivers"},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="driver_id", type="integer", example=1),
     *             @OA\Property(property="vehicle_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Driver assigned to vehicle"),
     *     @OA\Response(response=400, description="Invalid driver or vehicle"),
     *     @OA\Response(response=404, description="Driver not found")
     * )
     */
    public function assign(Request $request)
    {
        try {
            $validated = $request->validate([
                'driver_id' => 'required|integer',
                'vehicle_id' => 'required|integer',
            ]);

            $driver = Driver::find($validated['driver_id']);
            if (!$driver) {
                return response()->json(['error' => 'Driver not found'], 404);
            }
            if ($driver->status !== 'available') {
                return response()->json(['error' => 'Driver is not available'], 400);
            }

            $driver->update([
                'vehicle_id' => $validated['vehicle_id'],
                'status' => 'on_duty'
            ]);
            return new DriverResource($driver);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to assign driver'], 500);
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
     *             @OA\Property(property="user_id", type="integer", example=1),
     *             @OA\Property(property="license_number", type="string", example="LIC123456"),
     *             @OA\Property(property="status", type="string", example="available"),
     *             @OA\Property(property="vehicle_id", type="integer", example=1, nullable=true)
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
                'user_id' => 'required|integer|unique:drivers,user_id,' . $id,
                'license_number' => 'required|string|unique:drivers,license_number,' . $id . '|max:50',
                'status' => 'nullable|string|in:available,on_duty,unavailable',
                'vehicle_id' => 'nullable|integer',
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