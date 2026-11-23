<?php

declare(strict_types=1);

namespace Tests\Unit\Characterization;

use App\Application\Common\EnterpriseServiceMeshMediator;
use App\Application\DTO\CreateAppointmentDTO;
use App\Application\DTO\RescheduleAppointmentDTO;
use App\Application\Service\AppointmentService;
use App\Application\Service\IcalCalendarService;
use App\Domain\Exception\AppointmentConflictException;
use App\Domain\Exception\SlotUnavailableException;
use App\Domain\Model\Appointment;
use App\Domain\Model\AppointmentStatus;
use App\Domain\Model\Availability;
use App\Domain\Model\Patient;
use App\Domain\Model\Practitioner;
use App\Domain\Model\Speciality;
use App\Domain\Model\TimeSlot;
use App\Domain\Repository\AppointmentRepositoryInterface;
use App\Domain\Repository\AvailabilityRepositoryInterface;
use App\Domain\Repository\PatientRepositoryInterface;
use App\Domain\Repository\PractitionerRepositoryInterface;
use App\Domain\Service\SlotCalculatorService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class AppointmentBookingCharacterizationTest extends TestCase
{
    private const PRACTITIONER_ID = 'practitioner-1';
    private const PATIENT_ID = 'patient-1';

    private AppointmentRepositoryInterface $appointmentRepository;
    private AvailabilityRepositoryInterface $availabilityRepository;
    private PatientRepositoryInterface $patientRepository;
    private PractitionerRepositoryInterface $practitionerRepository;
    private Practitioner $practitioner;
    private Patient $patient;
    private Availability $availability;
    private TimeSlot $slot;
    private DateTimeImmutable $now;
    private EnterpriseServiceMeshMediator $mediator;

    protected function setUp(): void
    {
        $this->appointmentRepository = $this->createMock(AppointmentRepositoryInterface::class);
        $this->availabilityRepository = $this->createMock(AvailabilityRepositoryInterface::class);
        $this->patientRepository = $this->createMock(PatientRepositoryInterface::class);
        $this->practitionerRepository = $this->createMock(PractitionerRepositoryInterface::class);

        $this->practitioner = new Practitioner(
            self::PRACTITIONER_ID,
            'Sophie',
            'Martin',
            'sophie@example.test',
            '0102030405',
            Speciality::GENERAL_PRACTICE,
            '1 rue de test',
            'Paris',
            '75001'
        );
        $this->patient = new Patient(
            self::PATIENT_ID,
            'Jean',
            'Dupont',
            'jean@example.test',
            '0607080910'
        );
        $this->availability = new Availability(
            'availability-1',
            self::PRACTITIONER_ID,
            1,
            '09:00',
            '12:00'
        );
        $this->slot = new TimeSlot(
            new DateTimeImmutable('2030-01-07T10:00:00+00:00'),
            new DateTimeImmutable('2030-01-07T10:30:00+00:00')
        );
        $this->now = new DateTimeImmutable('2030-01-01T08:00:00+00:00');
        $this->mediator = new EnterpriseServiceMeshMediator();
        EnterpriseServiceMeshMediator::setInstance($this->mediator);
    }

    public function testBookingPersistsAnAppointmentForTheRequestedPractitionerAndPatient(): void
    {
        $this->expectPractitionerAndPatientLookups();
        $this->availabilityRepository->expects(self::once())
            ->method('findByPractitioner')
            ->with(self::PRACTITIONER_ID)
            ->willReturn([$this->availability]);
        $this->appointmentRepository->expects(self::once())
            ->method('findByPractitioner')
            ->with(
                self::PRACTITIONER_ID,
                self::equalTo($this->slot->startTime->setTime(0, 0, 0)),
                self::equalTo($this->slot->startTime->setTime(23, 59, 59))
            )
            ->willReturn([]);
        $this->appointmentRepository->expects(self::once())
            ->method('findOverlappingForPatient')
            ->with(self::PATIENT_ID, self::identicalTo($this->slot))
            ->willReturn([]);

        $savedAppointment = null;
        $this->appointmentRepository->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (Appointment $appointment) use (&$savedAppointment): void {
                $savedAppointment = $appointment;
            });

        $service = $this->createAppointmentService();
        $appointment = $service->bookAppointment(
            new CreateAppointmentDTO(self::PRACTITIONER_ID, self::PATIENT_ID, $this->slot, 'Consultation'),
            $this->now
        );

        self::assertSame(self::PRACTITIONER_ID, $appointment->getPractitionerId());
        self::assertSame(self::PATIENT_ID, $appointment->getPatientId());
        self::assertSame($this->slot, $appointment->getSlot());
        self::assertSame(AppointmentStatus::CONFIRMED, $appointment->getStatus());
        self::assertSame('Consultation', $appointment->getReason());
        self::assertSame($appointment, $savedAppointment);
    }

    public function testBookingRejectsAnOverlappingPractitionerAppointmentWithoutSaving(): void
    {
        $existingAppointment = $this->existingAppointment('existing-practitioner-appointment');
        $this->expectPractitionerAndPatientLookups();
        $this->availabilityRepository->expects(self::once())
            ->method('findByPractitioner')
            ->with(self::PRACTITIONER_ID)
            ->willReturn([$this->availability]);
        $this->appointmentRepository->expects(self::once())
            ->method('findByPractitioner')
            ->willReturn([$existingAppointment]);
        $this->appointmentRepository->expects(self::once())
            ->method('findOverlappingForPractitioner')
            ->with(self::PRACTITIONER_ID, self::identicalTo($this->slot))
            ->willReturn([$existingAppointment]);
        $this->appointmentRepository->expects(self::never())->method('save');
        $this->appointmentRepository->expects(self::never())->method('findOverlappingForPatient');

        $this->expectException(AppointmentConflictException::class);

        $this->createAppointmentService()->bookAppointment(
            new CreateAppointmentDTO(self::PRACTITIONER_ID, self::PATIENT_ID, $this->slot),
            $this->now
        );
    }

    public function testBookingRejectsAnOverlappingPatientAppointmentWithoutSaving(): void
    {
        $existingAppointment = $this->existingAppointment('existing-patient-appointment', 'practitioner-2');
        $this->expectPractitionerAndPatientLookups();
        $this->availabilityRepository->expects(self::once())
            ->method('findByPractitioner')
            ->with(self::PRACTITIONER_ID)
            ->willReturn([$this->availability]);
        $this->appointmentRepository->expects(self::once())
            ->method('findByPractitioner')
            ->willReturn([]);
        $this->appointmentRepository->expects(self::once())
            ->method('findOverlappingForPatient')
            ->with(self::PATIENT_ID, self::identicalTo($this->slot))
            ->willReturn([$existingAppointment]);
        $this->appointmentRepository->expects(self::never())->method('save');

        $this->expectException(AppointmentConflictException::class);

        $this->createAppointmentService()->bookAppointment(
            new CreateAppointmentDTO(self::PRACTITIONER_ID, self::PATIENT_ID, $this->slot),
            $this->now
        );
    }

    public function testBookingOutsidePractitionerAvailabilityDoesNotSave(): void
    {
        $outsideAvailability = new TimeSlot(
            new DateTimeImmutable('2030-01-07T13:00:00+00:00'),
            new DateTimeImmutable('2030-01-07T13:30:00+00:00')
        );
        $this->expectPractitionerAndPatientLookups();
        $this->availabilityRepository->expects(self::once())
            ->method('findByPractitioner')
            ->with(self::PRACTITIONER_ID)
            ->willReturn([$this->availability]);
        $this->appointmentRepository->expects(self::once())
            ->method('findByPractitioner')
            ->willReturn([]);
        $this->appointmentRepository->expects(self::once())
            ->method('findOverlappingForPractitioner')
            ->with(self::PRACTITIONER_ID, self::identicalTo($outsideAvailability))
            ->willReturn([]);
        $this->appointmentRepository->expects(self::never())->method('save');

        $this->expectException(SlotUnavailableException::class);

        $this->createAppointmentService()->bookAppointment(
            new CreateAppointmentDTO(self::PRACTITIONER_ID, self::PATIENT_ID, $outsideAvailability),
            $this->now
        );
    }

    public function testBookingInThePastDoesNotCheckAvailabilityOrSave(): void
    {
        $pastSlot = new TimeSlot(
            new DateTimeImmutable('2029-12-31T10:00:00+00:00'),
            new DateTimeImmutable('2029-12-31T10:30:00+00:00')
        );
        $this->expectPractitionerAndPatientLookups();
        $this->availabilityRepository->expects(self::never())->method('findByPractitioner');
        $this->appointmentRepository->expects(self::never())->method('findByPractitioner');
        $this->appointmentRepository->expects(self::never())->method('save');

        $this->expectException(SlotUnavailableException::class);

        $this->createAppointmentService()->bookAppointment(
            new CreateAppointmentDTO(self::PRACTITIONER_ID, self::PATIENT_ID, $pastSlot),
            $this->now
        );
    }

    public function testBookingRejectsAnIcalBusySlotWithoutSaving(): void
    {
        $practitioner = $this->practitionerWithCalendarUrl();
        $this->expectPractitionerAndPatientLookups($practitioner);
        $this->availabilityRepository->expects(self::once())
            ->method('findByPractitioner')
            ->with(self::PRACTITIONER_ID)
            ->willReturn([$this->availability]);
        $this->appointmentRepository->expects(self::once())
            ->method('findByPractitioner')
            ->willReturn([]);
        $this->appointmentRepository->expects(self::once())
            ->method('findOverlappingForPractitioner')
            ->with(self::PRACTITIONER_ID, self::identicalTo($this->slot))
            ->willReturn([]);
        $this->appointmentRepository->expects(self::never())->method('save');

        $calendar = file_get_contents(__DIR__ . '/fixtures/doctor-calendar.ics');
        self::assertIsString($calendar);
        $calendarService = new IcalCalendarService(static fn(string $url): string => $calendar);
        $service = $this->createAppointmentService($calendarService);

        $this->expectException(AppointmentConflictException::class);
        $this->expectExceptionMessage('calendrier');

        $service->bookAppointment(
            new CreateAppointmentDTO(self::PRACTITIONER_ID, self::PATIENT_ID, $this->slot),
            $this->now
        );
    }

    public function testBookingContinuesWhenTheIcalFeedCannotBeFetched(): void
    {
        $practitioner = $this->practitionerWithCalendarUrl();
        $this->expectPractitionerAndPatientLookups($practitioner);
        $this->availabilityRepository->expects(self::once())
            ->method('findByPractitioner')
            ->with(self::PRACTITIONER_ID)
            ->willReturn([$this->availability]);
        $this->appointmentRepository->expects(self::once())
            ->method('findByPractitioner')
            ->willReturn([]);
        $this->appointmentRepository->expects(self::once())
            ->method('findOverlappingForPatient')
            ->with(self::PATIENT_ID, self::identicalTo($this->slot))
            ->willReturn([]);
        $this->appointmentRepository->expects(self::once())->method('save');

        $calendarService = new IcalCalendarService(static function (): string {
            throw new \RuntimeException('Calendar is unavailable.');
        });
        $appointment = $this->createAppointmentService($calendarService)->bookAppointment(
            new CreateAppointmentDTO(self::PRACTITIONER_ID, self::PATIENT_ID, $this->slot),
            $this->now
        );

        self::assertSame(self::PRACTITIONER_ID, $appointment->getPractitionerId());
        self::assertSame(self::PATIENT_ID, $appointment->getPatientId());
    }

    public function testReschedulingRejectsAnIcalBusySlotWithoutSaving(): void
    {
        $practitioner = $this->practitionerWithCalendarUrl();
        $this->practitionerRepository->expects(self::once())
            ->method('findById')
            ->with(self::PRACTITIONER_ID)
            ->willReturn($practitioner);
        $this->availabilityRepository->expects(self::once())
            ->method('findByPractitioner')
            ->with(self::PRACTITIONER_ID)
            ->willReturn([$this->availability]);

        $existingSlot = new TimeSlot(
            new DateTimeImmutable('2029-12-30T10:00:00+00:00'),
            new DateTimeImmutable('2029-12-30T10:30:00+00:00')
        );
        $existingAppointment = new Appointment(
            'appointment-to-reschedule',
            self::PRACTITIONER_ID,
            self::PATIENT_ID,
            $existingSlot,
            AppointmentStatus::CONFIRMED
        );
        $this->appointmentRepository->expects(self::once())
            ->method('findById')
            ->with('appointment-to-reschedule')
            ->willReturn($existingAppointment);
        $this->appointmentRepository->expects(self::once())
            ->method('findByPractitioner')
            ->willReturn([$existingAppointment]);
        $this->appointmentRepository->expects(self::once())
            ->method('findOverlappingForPractitioner')
            ->with(
                self::PRACTITIONER_ID,
                self::identicalTo($this->slot),
                'appointment-to-reschedule'
            )
            ->willReturn([]);
        $this->appointmentRepository->expects(self::never())->method('save');

        $calendar = file_get_contents(__DIR__ . '/fixtures/doctor-calendar.ics');
        self::assertIsString($calendar);
        $calendarService = new IcalCalendarService(static fn(string $url): string => $calendar);
        $service = $this->createAppointmentService($calendarService);

        $this->expectException(AppointmentConflictException::class);
        $this->expectExceptionMessage('calendrier');

        $service->rescheduleAppointment(
            'appointment-to-reschedule',
            new RescheduleAppointmentDTO($this->slot),
            $this->now
        );
    }

    public function testAvailableSlotsExcludeBusyIcalEvents(): void
    {
        $practitioner = $this->practitionerWithCalendarUrl();
        $this->practitionerRepository->expects(self::once())
            ->method('findById')
            ->with(self::PRACTITIONER_ID)
            ->willReturn($practitioner);
        $this->availabilityRepository->expects(self::once())
            ->method('findByPractitioner')
            ->with(self::PRACTITIONER_ID)
            ->willReturn([$this->availability]);
        $this->appointmentRepository->expects(self::once())
            ->method('findByPractitioner')
            ->with(
                self::PRACTITIONER_ID,
                self::equalTo(new DateTimeImmutable('2030-01-07T00:00:00+00:00')),
                self::equalTo(new DateTimeImmutable('2030-01-07T23:59:59+00:00'))
            )
            ->willReturn([]);

        $calendar = file_get_contents(__DIR__ . '/fixtures/doctor-calendar.ics');
        self::assertIsString($calendar);
        $calendarService = new IcalCalendarService(static fn(string $url): string => $calendar);
        $slotsByDate = $this->createAppointmentService($calendarService)->getAvailableSlots(
            self::PRACTITIONER_ID,
            new DateTimeImmutable('2030-01-07T00:00:00+00:00'),
            new DateTimeImmutable('2030-01-07T00:00:00+00:00'),
            new DateTimeImmutable('2030-01-07T09:00:00+00:00')
        );
        $startTimes = array_column($slotsByDate['2030-01-07'], 'start_time');

        self::assertNotContains('2030-01-07T10:00:00+00:00', $startTimes);
        self::assertNotContains('2030-01-07T10:30:00+00:00', $startTimes);
        self::assertContains('2030-01-07T09:30:00+00:00', $startTimes);
        self::assertContains('2030-01-07T11:00:00+00:00', $startTimes);
    }

    private function expectPractitionerAndPatientLookups(?Practitioner $practitioner = null): void
    {
        $this->practitionerRepository->expects(self::once())
            ->method('findById')
            ->with(self::PRACTITIONER_ID)
            ->willReturn($practitioner ?? $this->practitioner);
        $this->patientRepository->expects(self::once())
            ->method('findById')
            ->with(self::PATIENT_ID)
            ->willReturn($this->patient);
    }

    private function createAppointmentService(?IcalCalendarService $icalCalendarService = null): AppointmentService
    {
        return new AppointmentService(
            $this->appointmentRepository,
            $this->practitionerRepository,
            $this->patientRepository,
            $this->availabilityRepository,
            new SlotCalculatorService(),
            $this->mediator,
            $icalCalendarService
        );
    }

    private function practitionerWithCalendarUrl(): Practitioner
    {
        return new Practitioner(
            self::PRACTITIONER_ID,
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
            icalUrl: 'https://calendar.example.test/private.ics'
        );
    }

    private function existingAppointment(string $id, string $practitionerId = self::PRACTITIONER_ID): Appointment
    {
        return new Appointment(
            $id,
            $practitionerId,
            self::PATIENT_ID,
            $this->slot,
            AppointmentStatus::CONFIRMED
        );
    }
}
