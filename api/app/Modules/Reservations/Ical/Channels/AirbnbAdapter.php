<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Ical\Channels;

use App\Modules\Reservations\DTO\ParseResult;
use App\Modules\Reservations\DTO\ParseWarning;
use App\Modules\Reservations\DTO\ReservationEvent;
use App\Modules\Reservations\Enums\IcalErrorCode;
use App\Modules\Reservations\Enums\IcalWarningCode;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Exceptions\InvalidIcalException;
use DateTimeImmutable;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;

final class AirbnbAdapter implements ChannelAdapterInterface
{
    public function channel(): string
    {
        return 'airbnb';
    }

    public function parseFeed(string $icalRawText, int $propertyId): ParseResult
    {
        //tratar empty
        if (trim($icalRawText) === '') {
            throw new InvalidIcalException(IcalErrorCode::EmptyFile, 'O ficheiro está vazio.');
        }

        //tratar not-vcalendar
        try {
            $calendar = Reader::read($icalRawText);
        } catch (ParseException) {
            throw new InvalidIcalException(IcalErrorCode::InvalidIcal, 'O ficheiro não é um VCALENDAR válido.');
        }

        if (! $calendar instanceof VCalendar) {
            throw new InvalidIcalException(IcalErrorCode::InvalidIcal, 'O ficheiro não é um VCALENDAR válido.');
        }

        $events = [];
        $warnings = [];
        $seenUids = [];

        foreach($calendar->select('VEVENT') as $vevent){
            $uid = isset($vevent->UID) ? trim((string) $vevent->UID) : '';
            
            if ($uid === '') {
                $warnings[] = new ParseWarning(
                    null,
                    IcalWarningCode::MissingUid,
                    'VEVENT sem UID. Evento ignorado.',
                );

                continue;
            }
            
            if (isset($seenUids[$uid])) {
                $warnings[] = new ParseWarning(
                    $uid,
                    IcalWarningCode::DuplicateUid,
                    'UID repetido no ficheiro. Mantido o primeiro; este evento foi ignorado.',
                );

                continue;
            }

            $seenUids[$uid] = true;

            if (! isset($vevent->DTSTART, $vevent->DTEND)) {
                $warnings[] = new ParseWarning(
                    $uid,
                    IcalWarningCode::MissingDates,
                    'Falta DTSTART ou DTEND. Evento ignorado.',
                );

                continue;
            }

            if ($vevent->DTSTART->hasTime() || $vevent->DTEND->hasTime()) {
                $warnings[] = new ParseWarning(
                    $uid,
                    IcalWarningCode::UnsupportedDateTime,
                    'Datas com hora não são suportadas (apenas VALUE=DATE). Evento ignorado.',
                );

                continue;
            }

            $checkIn = DateTimeImmutable::createFromInterface($vevent->DTSTART->getDateTime());
            $checkOut = DateTimeImmutable::createFromInterface($vevent->DTEND->getDateTime());

            if ($checkOut <= $checkIn) {
                $warnings[] = new ParseWarning(
                    $uid,
                    IcalWarningCode::InvalidDateRange,
                    'DTEND tem de ser posterior a DTSTART. Evento ignorado.',
                );

                continue;
            }

            $summary = isset($vevent->SUMMARY) ? (string) $vevent->SUMMARY : null;
            $status = $this->classify($summary);

            if ($status === ReservationStatus::Unknown) {
                $warnings[] = new ParseWarning(
                    $uid,
                    IcalWarningCode::UnknownSummary,
                    "SUMMARY não reconhecido: '{$summary}'. Evento classificado como unknown.",
                );
            }

            $events[] = new ReservationEvent(
                externalUid: $uid,
                propertyId: $propertyId,
                channel: $this->channel(),
                checkIn: $checkIn,
                checkOut: $checkOut,
                status: $status,
                sequence: isset($vevent->SEQUENCE) ? (int) $vevent->SEQUENCE->getValue() : null,
                reservationUrl: $this->extractReservationUrl(
                    isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
                ),
                rawSummary: $summary,
                rawDescription: null,
            );
        }
        return new ParseResult($events, $warnings);
    }

        private function classify(?string $summary): ReservationStatus
    {
        //match compara com ===
        return match ($summary) {
            'Reserved' => ReservationStatus::Reserved,
            'Airbnb (Not available)' => ReservationStatus::Blocked,
            default => ReservationStatus::Unknown,
        };
    }

        private function extractReservationUrl(?string $description): ?string
    {
        // retornar se description vazia
        if ($description === null) {
            return null;
        }
        //se encontrar url == 1
        //!== 1 para cobrir o padrão "false"
        if (preg_match('/Reservation URL:\s*(\S+)/', $description, $matches) !== 1) {
            return null;
        }
        // retorna apenas o url
        return $matches[1];
    }
}