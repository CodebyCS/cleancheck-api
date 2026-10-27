<?php

namespace App\Modules\Properties\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Properties\Http\Resources\ServiceProviderResource;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Profile of the authenticated service provider. The identity always
     * comes from the session, so no provider id is accepted in the URL.
     */
    public function show(Request $request): ServiceProviderResource
    {
        $serviceProvider = $request->user()->serviceProvider;

        abort_unless($serviceProvider, 404, 'Esta conta não tem perfil de prestador.');

        return new ServiceProviderResource($serviceProvider->load('user'));
    }
}
