<?php

namespace App\Http\Controllers;

use App\Models\TaskHistory;
use App\Http\Requests\StoreTaskHistoryRequest;
use App\Http\Requests\UpdateTaskHistoryRequest;

class TaskHistoryController extends Controller
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
    public function store(StoreTaskHistoryRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(TaskHistory $taskHistory)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTaskHistoryRequest $request, TaskHistory $taskHistory)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TaskHistory $taskHistory)
    {
        //
    }
}
