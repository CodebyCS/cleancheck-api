<?php

namespace App\Http\Controllers;

use App\Models\ChecklistItem;
use App\Http\Requests\StoreChecklistItemRequest;
use App\Http\Requests\UpdateChecklistItemRequest;

class ChecklistItemController extends Controller
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
    public function store(StoreChecklistItemRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(ChecklistItem $checklistItem)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateChecklistItemRequest $request, ChecklistItem $checklistItem)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ChecklistItem $checklistItem)
    {
        //
    }
}
