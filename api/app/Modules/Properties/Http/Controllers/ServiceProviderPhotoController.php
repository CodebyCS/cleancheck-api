<?php

namespace App\Modules\Properties\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Properties\Http\Requests\UploadServiceProviderPhotoRequest;
use App\Modules\Properties\Http\Resources\ServiceProviderResource;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServiceProviderPhotoController extends Controller
{
    private const DISK = 'local';

    public function store(
        UploadServiceProviderPhotoRequest $request,
        ServiceProvider $serviceProvider,
    ): ServiceProviderResource {
        $disk = Storage::disk(self::DISK);
        $previousPath = $serviceProvider->photo_path;

        $path = $request->file('photo')->store("service-providers/{$serviceProvider->id}", self::DISK);

        $serviceProvider->forceFill(['photo_path' => $path])->save();

        if ($previousPath && $previousPath !== $path) {
            $disk->delete($previousPath);
        }

        return new ServiceProviderResource($serviceProvider->fresh('user'));
    }

    public function show(ServiceProvider $serviceProvider): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);

        abort_unless(
            $serviceProvider->photo_path && $disk->exists($serviceProvider->photo_path),
            404,
        );

        return $disk->response($serviceProvider->photo_path);
    }
}
