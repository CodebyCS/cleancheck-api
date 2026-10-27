<?php

namespace App\Modules\Properties\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Modules\Properties\Http\Requests\StoreServiceProviderRequest;
use App\Modules\Properties\Http\Requests\UpdateServiceProviderRequest;
use App\Modules\Properties\Http\Resources\ServiceProviderResource;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ServiceProviderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasRole('admin', 'supervisor'), 403);

        $providers = ServiceProvider::query()
            ->with('user')
            ->join('users', 'users.id', '=', 'service_providers.user_id')
            ->when(
                $request->has('active'),
                fn ($query) => $query->where('users.is_active', $request->boolean('active')),
            )
            ->orderBy('users.name')
            ->select('service_providers.*')
            ->get();

        return ServiceProviderResource::collection($providers);
    }

    public function store(StoreServiceProviderRequest $request): JsonResponse
    {
        $provider = DB::transaction(function () use ($request): ServiceProvider {
            $user = new User($request->safe()->only(['name', 'email', 'password']));
            $user->role()->associate(Role::where('name', 'service_provider')->firstOrFail());
            $user->is_active = true;
            $user->save();

            return $user->serviceProvider()->create(
                $request->safe()->only(['phone', 'address', 'postal_code', 'city']),
            );
        });

        return (new ServiceProviderResource($provider->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateServiceProviderRequest $request,
        ServiceProvider $serviceProvider,
    ): ServiceProviderResource {
        DB::transaction(function () use ($request, $serviceProvider): void {
            $user = $serviceProvider->user;
            $user->fill($request->safe()->only(['name', 'email']));

            if ($request->filled('password')) {
                $user->password = $request->validated('password');
            }

            $user->save();

            $serviceProvider->update(
                $request->safe()->only(['phone', 'address', 'postal_code', 'city']),
            );
        });

        return new ServiceProviderResource($serviceProvider->fresh('user'));
    }

    public function activate(Request $request, ServiceProvider $serviceProvider): ServiceProviderResource
    {
        abort_unless($request->user()->hasRole('admin', 'supervisor'), 403);

        $user = $serviceProvider->user;
        $user->is_active = true;
        $user->save();

        return new ServiceProviderResource($serviceProvider->fresh('user'));
    }

    public function deactivate(Request $request, ServiceProvider $serviceProvider): ServiceProviderResource
    {
        abort_unless($request->user()->hasRole('admin', 'supervisor'), 403);

        DB::transaction(function () use ($serviceProvider): void {
            $user = $serviceProvider->user;
            $user->is_active = false;
            $user->save();

            // Ends the mobile sessions; web sessions end on the next request
            // through the active middleware.
            $user->tokens()->delete();
        });

        return new ServiceProviderResource($serviceProvider->fresh('user'));
    }
}
