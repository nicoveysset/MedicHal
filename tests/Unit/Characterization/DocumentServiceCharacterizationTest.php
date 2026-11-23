<?php

declare(strict_types=1);

namespace Tests\Unit\Characterization;

use App\Application\Service\DocumentService;
use App\Domain\Model\DocumentType;
use App\Domain\Model\MedicalDocument;
use App\Domain\Repository\DocumentRepositoryInterface;
use App\Domain\Repository\PatientRepositoryInterface;
use App\Domain\Repository\PractitionerRepositoryInterface;
use PHPUnit\Framework\TestCase;

class DocumentServiceCharacterizationTest extends TestCase
{
    private const SNAPSHOT_PATH = __DIR__ . '/fixtures/document-service.json';

    public function testRecordSnapshot(): void
    {
        if (getenv('UPDATE_CHARACTERIZATION') !== '1') {
            $this->markTestSkipped('Set UPDATE_CHARACTERIZATION=1 to record the current behavior.');
        }

        $snapshot = json_encode(
            $this->observeDocumentService(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        self::assertNotFalse(file_put_contents(self::SNAPSHOT_PATH, $snapshot));
    }

    public function testReplaySnapshot(): void
    {
        $expected = file_get_contents(self::SNAPSHOT_PATH);
        self::assertNotFalse($expected, 'Characterization snapshot is missing.');

        $actual = json_encode(
            $this->observeDocumentService(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        self::assertJsonStringEqualsJsonString($expected, $actual);
    }

    private function observeDocumentService(): array
    {
        $documentRepository = $this->createMock(DocumentRepositoryInterface::class);
        $patientRepository = $this->createMock(PatientRepositoryInterface::class);
        $practitionerRepository = $this->createMock(PractitionerRepositoryInterface::class);
        $documents = [
            MedicalDocument::create('pat-1', 'pract-1', 'Doc 1', DocumentType::PRESCRIPTION, 'f1.pdf', 'application/pdf', 100),
            MedicalDocument::create('pat-1', 'pract-1', 'Doc 2', DocumentType::PRESCRIPTION, 'f2.pdf', 'application/pdf', 200),
            MedicalDocument::create('pat-1', 'pract-1', 'Doc 3', DocumentType::PRESCRIPTION, 'f3.pdf', 'application/pdf', 300),
        ];

        $documentRepository->expects($this->exactly(3))
            ->method('findAll')
            ->with('PRESCRIPTION', 'pat-1')
            ->willReturn($documents);
        $documentRepository->expects($this->once())
            ->method('delete')
            ->with('doc-1');

        $service = new DocumentService($documentRepository, $patientRepository, $practitionerRepository);
        $filters = ['type' => 'PRESCRIPTION', 'patient_id' => 'pat-1'];
        $pagination = [];

        foreach ([0, 1, 2] as $offset) {
            $page = $service->listDocuments('PRESCRIPTION', 'pat-1', 2, $offset);
            $pagination[] = [
                'total' => $page['total'],
                'limit' => $page['limit'],
                'offset' => $page['offset'],
                'titles' => array_column($page['items'], 'title'),
            ];
        }

        return [
            'export' => $service->exportDocumentsStub($filters),
            'ocr_empty' => $service->analyzeDocumentOcrTest([]),
            'pagination' => $pagination,
            'delete_return' => $service->deleteDocument('doc-1'),
        ];
    }
}