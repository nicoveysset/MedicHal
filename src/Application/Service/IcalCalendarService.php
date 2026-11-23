<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Model\Practitioner;
use App\Domain\Model\TimeSlot;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use UnexpectedValueException;

class IcalCalendarService
{
    private readonly Closure $fetchCalendar;

    public function __construct(?Closure $fetchCalendar = null)
    {
        $this->fetchCalendar = $fetchCalendar ?? self::fetchRemoteCalendar(...);
    }

    /**
     * @return TimeSlot[]
     */
    public function findBusySlots(Practitioner $practitioner, TimeSlot $range): array
    {
        $url = trim($practitioner->getIcalUrl() ?? '');
        if ($url === '') {
            return [];
        }

        try {
            $calendar = ($this->fetchCalendar)($url);
            if (!is_string($calendar)) {
                throw new UnexpectedValueException('The calendar feed did not return text.');
            }

            return $this->parseBusySlots($calendar, $range);
        } catch (RuntimeException | \InvalidArgumentException $exception) {
            error_log(sprintf(
                'Unable to verify iCal calendar for practitioner %s: %s',
                $practitioner->getId(),
                $exception->getMessage()
            ));

            return [];
        }
    }

    /**
     * @return TimeSlot[]
     */
    private function parseBusySlots(string $calendar, TimeSlot $range): array
    {
        $unfoldedCalendar = preg_replace('/\r?\n[ \t]/', '', $calendar);
        if ($unfoldedCalendar === null) {
            throw new UnexpectedValueException('The calendar feed could not be parsed.');
        }
        if (!preg_match('/^BEGIN:VCALENDAR\r?$/im', $unfoldedCalendar)
            || !preg_match('/^END:VCALENDAR\r?$/im', $unfoldedCalendar)
        ) {
            throw new UnexpectedValueException('The response is not a valid iCalendar feed.');
        }

        $busySlots = [];
        $event = null;

        foreach (preg_split('/\r\n|\n|\r/', $unfoldedCalendar) as $line) {
            $line = trim($line);

            if (strcasecmp($line, 'BEGIN:VEVENT') === 0) {
                $event = ['start' => null, 'end' => null, 'cancelled' => false];
                continue;
            }

            if (strcasecmp($line, 'END:VEVENT') === 0) {
                if ($event !== null
                    && !$event['cancelled']
                    && $event['start'] instanceof DateTimeImmutable
                    && $event['end'] instanceof DateTimeImmutable
                ) {
                    $slot = new TimeSlot($event['start'], $event['end']);
                    if ($slot->overlapsWith($range)) {
                        $busySlots[] = $slot;
                    }
                }

                $event = null;
                continue;
            }

            if ($event === null) {
                continue;
            }

            if (strcasecmp($line, 'STATUS:CANCELLED') === 0) {
                $event['cancelled'] = true;
                continue;
            }

            if (!preg_match('/^(DTSTART|DTEND)((?:;[^:]*)?):(.+)$/i', $line, $matches)) {
                continue;
            }

            $property = strtoupper($matches[1]) === 'DTSTART' ? 'start' : 'end';
            $event[$property] = $this->parseDate($matches[3], $matches[2]);
        }

        if ($event !== null) {
            throw new UnexpectedValueException('The iCalendar feed contains an incomplete event.');
        }

        return $busySlots;
    }

    private function parseDate(string $value, string $parameters): DateTimeImmutable
    {
        try {
            $timeZone = new DateTimeZone(date_default_timezone_get());
            if (preg_match('/(?:^|;)TZID=("([^"]+)"|[^;]+)/i', $parameters, $matches)) {
                $timeZone = new DateTimeZone(trim($matches[1], '"'));
            }
        } catch (\Exception $exception) {
            throw new UnexpectedValueException('The iCalendar event has an invalid time zone.', 0, $exception);
        }

        if (preg_match('/^\d{8}$/', $value)) {
            $date = DateTimeImmutable::createFromFormat('!Ymd', $value, $timeZone);
        } else {
            if (preg_match('/^\d{8}T\d{4}Z?$/', $value)) {
                $value = substr($value, 0, 9) . '00' . substr($value, 9);
            }

            if (preg_match('/Z$/i', $value)) {
                $date = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', strtoupper($value), new DateTimeZone('UTC'));
            } else {
                $date = DateTimeImmutable::createFromFormat('!Ymd\THis', $value, $timeZone);
            }
        }

        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        ) {
            throw new UnexpectedValueException(sprintf('Invalid iCal date: %s', $value));
        }

        return $date;
    }

    private static function fetchRemoteCalendar(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new RuntimeException('The calendar URL must use HTTP or HTTPS.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the calendar request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_MAXFILESIZE => 2 * 1024 * 1024,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        $calendar = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($calendar) || $status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf(
                'Calendar request failed with HTTP status %d: %s',
                $status,
                $error
            ));
        }

        return $calendar;
    }
}
