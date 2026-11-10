<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reservations\Http\Resources\BookingNoticeResource;
use App\Modules\Reservations\Models\BookingNotice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class BookingNoticeController extends Controller
{
    private const PER_PAGE = 20;

    private const RELATIONS = ['booking.bookingSource.property', 'task.serviceProvider'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['unread' => ['sometimes', 'boolean']]);

        $notices = $this->ownNotices($request)
            ->when($request->boolean('unread'), fn (Builder $query) => $query->whereNull('read_at'))
            ->with(self::RELATIONS)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $unreadCount = $this->ownNotices($request)->whereNull('read_at')->count();

        return BookingNoticeResource::collection($notices)
            ->additional(['meta' => ['unreadCount' => $unreadCount]]);
    }

    public function show(Request $request, int $notice): BookingNoticeResource
    {
        return new BookingNoticeResource($this->findOwn($request, $notice));
    }

    public function markAsRead(Request $request, int $notice): BookingNoticeResource
    {
        $record = $this->findOwn($request, $notice);

        if ($record->read_at === null) {
            // Reading a notice must not move it to the top of the list.
            BookingNotice::withoutTimestamps(fn () => $record->update(['read_at' => now()]));
        }

        return new BookingNoticeResource($record);
    }

    private function ownNotices(Request $request): Builder
    {
        return BookingNotice::query()->where('user_id', $request->user()->id);
    }

    private function findOwn(Request $request, int $notice): BookingNotice
    {
        return $this->ownNotices($request)->with(self::RELATIONS)->findOrFail($notice);
    }
}