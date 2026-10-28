<?php

namespace App\Modules\Properties\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class ServiceProviderResource extends JsonResource
{
    private const PHOTO_URL_MINUTES = 10;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'photo_url' => $this->photoUrl($request),
            'is_active' => $this->user->is_active,
        ];
    }

    /**
     * Temporary signed link, only for admin, supervisor or the provider.
     */
    private function photoUrl(Request $request): ?string
    {
        $viewer = $request->user();

        if (! $this->photo_path || ! $viewer) {
            return null;
        }

        $canView = $viewer->hasRole('admin', 'supervisor') || $viewer->id === $this->user_id;

        if (! $canView) {
            return null;
        }

        return URL::temporarySignedRoute(
            'service-providers.photo',
            now()->addMinutes(self::PHOTO_URL_MINUTES),
            ['serviceProvider' => $this->id],
        );
    }
}
