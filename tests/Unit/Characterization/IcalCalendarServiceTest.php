<?php

declare(strict_types=1);

namespace Tests\Unit\Characterization;

use App\Application\Service\IcalCalendarService;
use App\Domain\Model\Practitioner;
use App\Domain\Model\Speciality;
use App\Domain\Model\TimeSlot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class IcalCalendarServiceTest extends TestCase
{
    private const CALENDAR_URL = 'https://calendar.example.test/private.ics';

    public function testReadsOnlyActiveEventsThatOverlapTheRequestedRange(): void
    {
        $calendar = file_get_contents(__DIR__ . '/fixtures/doctor-calendar.ics');
        self::assertIsString($calendar);
        $requestedRange = new TimeSlot(
            new DateTimeImmutable('2030-01-07T10:00:00+00:00'),
            new DateTimeImmutable('2030-01-07T10:30:00+00:00')
        );
        $requestedUrl = null;
        $service = new IcalCalendarService(static function (string $url) use ($calendar, &$requestedUrl): string {
            $requestedUrl = $url;
            return $calendar;
        });

        $busySlots = $service->findBusySlots($this->practitionerWithCalendarUrl(), $requestedRange);

        self::assertSame(self::CALENDAR_URL, $requestedUrl);
        self::assertCount(1, $busySlots);
        self::assertSame('2030-01-07T10:00:00+00:00', $busySlots[0]->startTime->format('Y-m-d\TH:i:sP'));
        self::assertSame('2030-01-07T10:30:00+00:00', $busySlots[0]->endTime->format('Y-m-d\TH:i:sP'));
    }

    public function testCalendarFetchFailureReturnsNoBusySlots(): void
    {
        $service = new IcalCalendarService(static function (): string {
            throw new RuntimeException('Calendar is unavailable.');
        });
        $requestedRange = new TimeSlot(
            new DateTimeImmutable('2030-01-07T10:00:00+00:00'),
            new DateTimeImmutable('2030-01-07T10:30:00+00:00')
        );

        $busySlots = $service->findBusySlots($this->practitionerWithCalendarUrl(), $requestedRange);

        self::assertSame([], $busySlots);
    }

    public function testPractitionerCalendarUrlIsPersistedButNotExposedInPublicData(): void
    {
        $practitioner = $this->practitionerWithCalendarUrl();
        $publicData = $practitioner->toArray();
        $persistedData = $practitioner->toPersistenceArray();

        self::assertArrayNotHasKey('ical_url', $publicData);
        self::assertSame(self::CALENDAR_URL, $persistedData['ical_url']);
        self::assertSame(self::CALENDAR_URL, Practitioner::fromArray($persistedData)->getIcalUrl());
    }

    private function practitionerWithCalendarUrl(): Practitioner
    {
        return new Practitioner(
            'practitioner-1',
            'Sophie',
            'Martin',
            'sophie@example.test',
            '0102030405',
            Speciality::GENERAL_PRACTICE,
            '1 rue de test',
            'Paris',
            '75001',
            25.0,
            30,
            icalUrl: self::CALENDAR_URL
        );
    }
}
