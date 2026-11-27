<?php

use App\Http\Controllers\AuthController;
use App\Http\Resources\UserResource;
use App\Modules\Billing\Http\Controllers\ProviderServiceEntryController;
use App\Modules\Billing\Http\Controllers\RateController;
use App\Modules\Billing\Http\Controllers\ServiceEntryAdjustmentController;
use App\Modules\Billing\Http\Controllers\ServiceEntryController;
use App\Modules\Checklists\Http\Controllers\ChecklistTemplateController;
use App\Modules\Checklists\Http\Controllers\TaskChecklistController;
use App\Modules\Evidence\Http\Controllers\EvidenceTrialController;
use App\Modules\Properties\Http\Controllers\ProfileController;
use App\Modules\Properties\Http\Controllers\PropertyController;
use App\Modules\Properties\Http\Controllers\ServiceProviderController;
use App\Modules\Properties\Http\Controllers\ServiceProviderPhotoController;
use App\Modules\Properties\Http\Controllers\TaskAssignmentWarningController;
use App\Modules\Reservations\Http\Controllers\IcalImportController;
use App\Modules\Reservations\Http\Controllers\IcalReadController;
use App\Modules\Reservations\Http\Controllers\BookingSourceController;
use App\Modules\Reservations\Http\Controllers\BookingController;
use App\Modules\Reservations\Http\Controllers\BookingNoticeController;
use App\Modules\Tasks\Http\Controllers\TaskController;
use App\Modules\Tasks\Http\Controllers\TaskEvidenceController;
use App\Modules\Tasks\Http\Controllers\TaskExecutionController;
use App\Modules\Tasks\Http\Controllers\TaskCheckoutInspectionController;
use App\Modules\Tasks\Http\Controllers\TaskImpedimentController;
use App\Modules\Tasks\Http\Controllers\ProviderTaskController;
use App\Modules\Alerts\Http\Controllers\TaskAlertController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public Diagnosis
Route::get('/health', function (): JsonResponse {
    return response()->json([
        'application' => 'CleanCheck', 'status' => 'ok',
    ]);
});

// The login must be accessible without authentication.
Route::post('/mobile/login', [AuthController::class, 'loginMobile'])
    ->middleware('throttle:5,1');
Route::post('/mobile/refresh', [AuthController::class, 'refreshMobile'])
    ->middleware('throttle:10,1');
Route::post('/mobile/logout-with-refresh', [AuthController::class, 'logoutMobileWithRefresh'],)
    ->middleware('throttle:10,1');

// Photo links are generated only for authorized viewers and expire.
Route::get('/service-providers/{serviceProvider}/photo', [ServiceProviderPhotoController::class, 'show'])
    ->whereNumber('serviceProvider')
    ->middleware('signed')
    ->name('service-providers.photo');

// Allow an authenticated mobile session to be terminated.
Route::post('/mobile/logout', [AuthController::class, 'logoutMobile'])
    ->middleware('auth:sanctum');

// Transactions that require identification and an active account.
Route::middleware(['auth:sanctum', 'access.valid'])->group(function () : void {
    Route::get('/user', function (Request $request) {
            $token = $request->user()->currentAccessToken();
            $expiresAt = $token instanceof
                \Laravel\Sanctum\PersonalAccessToken
                ? $token->expires_at?->toIso8601String()
                : \Carbon\CarbonImmutable::createFromTimestamp(
                    (int) $request->session()->get('access_expires_at')
                )->toIso8601String();

            return (new UserResource($request->user()))
                ->additional(['meta' => ['expires_at' => $expiresAt]]);

    });

    Route::get('/profile', [ProfileController::class, 'show']);
    
    // Booking notices: every user reads only their own.
    Route::get('/booking-notices', [BookingNoticeController::class, 'index']);
    Route::get('/booking-notices/{notice}', [BookingNoticeController::class, 'show'])
        ->whereNumber('notice');
    Route::patch('/booking-notices/{notice}/read', [BookingNoticeController::class, 'markAsRead'])
        ->whereNumber('notice');

    // Admin and Supervisor roles are allowed to access management routes.
    // Only administrators may retrieve private evidence trial files.
    Route::middleware('role:admin')->get('/evidence-trials/{evidenceTrial}/file', [EvidenceTrialController::class, 'download'])
        ->whereNumber('evidenceTrial');

    Route::middleware('role:admin,supervisor')->group(function (): void {
        // iCal
        Route::post('/reservations/ical/read', IcalReadController::class);
        Route::post('/reservations/ical/import', IcalImportController::class);

        // Tasks
        Route::get('/tasks', [TaskController::class, 'index']);
        Route::get('/tasks/assignment-warnings', [TaskAssignmentWarningController::class, 'index']);
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->whereNumber('task');
        Route::get('/tasks/{task}/executions', [TaskExecutionController::class, 'index'])->whereNumber('task');
        Route::get('/tasks/{task}/checkout-inspection', [TaskCheckoutInspectionController::class, 'index'])->whereNumber('task');
        Route::get('/tasks/{task}/evidence', [TaskEvidenceController::class, 'index'])
            ->whereNumber('task');
        Route::get('/tasks/{task}/evidence/{evidence}/file', [TaskEvidenceController::class, 'file'])
            ->whereNumber('task')
            ->whereNumber('evidence');
        Route::post('/tasks', [TaskController::class, 'store']);
        Route::patch('/tasks/{task}', [TaskController::class, 'update'])->whereNumber('task');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->whereNumber('task');
        Route::post('/tasks/{task}/restore', [TaskController::class, 'restore'])->whereNumber('task');
        Route::post('/tasks/{task}/cancel', [TaskController::class, 'cancel'])->whereNumber('task');
        Route::post('/tasks/complete-many', [TaskController::class, 'completeMany']);
        Route::post('/tasks/{task}/complete', [TaskController::class, 'complete'])->whereNumber('task');
        
        // Bookings
        Route::get('/properties/{property}/bookings', [BookingController::class, 'index'])
            ->whereNumber('property');
        Route::patch('/bookings/{booking}/schedule', [BookingController::class, 'updateSchedule'])
            ->whereNumber('booking');

        // Task alerts
        Route::get('/task-alerts', [TaskAlertController::class, 'index']);
        Route::patch('/task-alerts/{alert}/resolve', [TaskAlertController::class, 'resolve'])
            ->whereNumber('alert');

        // Billing: rates (append-only) and service entries review
        Route::get('/rates', [RateController::class, 'index']);
        Route::post('/rates', [RateController::class, 'store']);
        Route::get('/service-entries', [ServiceEntryController::class, 'index']);
        Route::get('/service-entries/{serviceEntry}', [ServiceEntryController::class, 'show'])->whereNumber('serviceEntry');
        Route::post('/tasks/{task}/service-entry', [ServiceEntryController::class, 'storeForTask'])->whereNumber('task');

        // Checklists: versioned templates per task type (append-only)
        Route::get('/checklist-templates', [ChecklistTemplateController::class, 'index']);
        Route::post('/checklist-templates', [ChecklistTemplateController::class, 'store']);
        Route::get('/checklist-templates/{checklistTemplate}', [ChecklistTemplateController::class, 'show'])
            ->whereNumber('checklistTemplate');
        Route::get('/tasks/{task}/checklist', [TaskChecklistController::class, 'showForStaff'])->whereNumber('task');
        Route::post('/service-entries/{serviceEntry}/adjustments', [ServiceEntryAdjustmentController::class, 'store'])
            ->whereNumber('serviceEntry');
    });

    Route::middleware('role:service_provider')->group(function (): void {
        Route::post('/tasks/{task}/impediments', [TaskImpedimentController::class, 'store'])->whereNumber('task');
        Route::post('/my/tasks/{task}/evidence-trials', [EvidenceTrialController::class, 'storeForTask'])
            ->whereNumber('task')
            ->middleware('throttle:10,1');
    
        Route::get('/my/tasks', [ProviderTaskController::class, 'index']);
        Route::get('/my/tasks/{task}', [ProviderTaskController::class, 'show'])->whereNumber('task');
        Route::get('/my/tasks/{task}/executions', [TaskExecutionController::class, 'index'])->whereNumber('task');
        Route::post('/my/tasks/{task}/executions', [TaskExecutionController::class, 'store'])->whereNumber('task');
        Route::get('/my/tasks/{task}/checkout-inspection', [TaskCheckoutInspectionController::class, 'index'])->whereNumber('task');
        Route::post('/my/tasks/{task}/checkout-inspection', [TaskCheckoutInspectionController::class, 'store'])->whereNumber('task');
        Route::post('/my/tasks/{task}/evidence', [TaskEvidenceController::class, 'store'])
            ->whereNumber('task')
            ->middleware('throttle:10,1');
        Route::post('/my/tasks/{task}/start', [ProviderTaskController::class, 'start'])->whereNumber('task');
        Route::post('/my/tasks/{task}/complete', [ProviderTaskController::class, 'complete'])->whereNumber('task');
        Route::patch('/my/tasks/{task}/notes', [ProviderTaskController::class, 'updateNotes'])->whereNumber('task');
        Route::get('/my/tasks/{task}/checklist', [TaskChecklistController::class, 'showForProvider'])->whereNumber('task');
        Route::patch('/my/tasks/{task}/checklist/items/{item}', [TaskChecklistController::class, 'markItem'])
            ->whereNumber(['task', 'item']);
        Route::get('/my/service-entries/summary', [ProviderServiceEntryController::class, 'summary']);
    });

    // Legacy unlinked trial route retained for older installed builds.
    Route::post('/evidence-trials', [EvidenceTrialController::class, 'store'])->middleware('throttle:10,1');

    Route::get('/properties', [PropertyController::class, 'index']);
    Route::post('/properties', [PropertyController::class, 'store']);
    Route::put('/properties/{property}', [PropertyController::class, 'update'])->whereNumber('property');
    Route::patch('/properties/{property}/activate', [PropertyController::class, 'activate'])->whereNumber('property');
    Route::patch('/properties/{property}/deactivate', [PropertyController::class, 'deactivate'])->whereNumber('property');

    Route::get('/properties/{property}/booking-sources', [BookingSourceController::class, 'index'])
        ->whereNumber('property');
    Route::post('/properties/{property}/booking-sources', [BookingSourceController::class, 'store'])
        ->whereNumber('property');
    Route::post('/booking-sources/{bookingSource}/sync', [BookingSourceController::class, 'sync'])
        ->whereNumber('bookingSource');
    Route::patch('/booking-sources/{bookingSource}', [BookingSourceController::class, 'update'])
        ->whereNumber('bookingSource');

    Route::get('/service-providers', [ServiceProviderController::class, 'index']);
    Route::post('/service-providers', [ServiceProviderController::class, 'store']);
    Route::put('/service-providers/{serviceProvider}', [ServiceProviderController::class, 'update'])->whereNumber('serviceProvider');
    Route::patch('/service-providers/{serviceProvider}/activate', [ServiceProviderController::class, 'activate'])->whereNumber('serviceProvider');
    Route::patch('/service-providers/{serviceProvider}/deactivate', [ServiceProviderController::class, 'deactivate'])->whereNumber('serviceProvider');
    Route::post('/service-providers/{serviceProvider}/photo', [ServiceProviderPhotoController::class, 'store'])->whereNumber('serviceProvider');
});

