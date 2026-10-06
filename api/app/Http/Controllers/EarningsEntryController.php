<?php

namespace App\Http\Controllers;

use App\Models\EarningsEntry;
use App\Http\Requests\StoreEarningsEntryRequest;
use App\Http\Requests\UpdateEarningsEntryRequest;

class EarningsEntryController extends Controller
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
    public function store(StoreEarningsEntryRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(EarningsEntry $earningsEntry)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEarningsEntryRequest $request, EarningsEntry $earningsEntry)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(EarningsEntry $earningsEntry)
    {
        //
    }
}
