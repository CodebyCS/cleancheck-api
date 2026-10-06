<?php

namespace App\Http\Controllers;

use App\Models\AvailabilitySchedule;
use App\Http\Requests\StoreAvailabilityScheduleRequest;
use App\Http\Requests\UpdateAvailabilityScheduleRequest;

class AvailabilityScheduleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAvailabilityScheduleRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(AvailabilitySchedule $availabilitySchedule)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAvailabilityScheduleRequest $request, AvailabilitySchedule $availabilitySchedule)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AvailabilitySchedule $availabilitySchedule)
    {
        //
    }
}
