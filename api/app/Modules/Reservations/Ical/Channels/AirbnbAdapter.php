<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Ical\Channels;

use App\Modules\Reservations\DTO\ParseResult;
use App\Modules\Reservations\DTO\ReservationEvent;
use App\Modules\Reservations\Enums\ReservationStatus;
use DateTimeImmutable;
use Sabre\VObject\Reader;

final class AirbnbAdapter implements ChannelAdapterInterface
{
    public function channel(): string
    {
        return 'airbnb';
    }

    public function parseFeed(string $icalRawText, int $propertyId): ParseResult
    {
        $calendar = Reader::read($icalRawText);

        $events = [];

        foreach($calendar->select('VEVENT') as $vevent){
            $events[] = new ReservationEvent(
                externalUid: (string) $vevent->UID,
                propertyId: $propertyId,
                channel: $this->channel(),
                checkIn:DateTimeImmutable::createFromInterface($vevent->DTSTART->getDateTime()),
                checkOut: DateTimeImmutable::createFromInterface($vevent->DTEND->getDateTime()),
                status: $this->classify(isset($vevent->SUMMARY) ? (string) $vevent->SUMMARY : null),
                sequence: isset($vevent->SEQUENCE) ? (int) $vevent->SEQUENCE->getValue() : null,
                reservationUrl: null,
                rawSummary: isset($vevent->SUMMARY) ? (string) $vevent->SUMMARY : null,
                rawDescription: null,
            );
        }
        return new ParseResult($events);
    }

        private function classify(?string $summary): ReservationStatus
    {
        return match ($summary) {
            'Reserved' => ReservationStatus::Reserved,
            'Airbnb (Not available)' => ReservationStatus::Blocked,
            default => ReservationStatus::Unknown,
        };
    }
}