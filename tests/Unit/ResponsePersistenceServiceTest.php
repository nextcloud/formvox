<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\FormFileLocator;
use OCA\FormVox\Service\FormLockManager;
use OCA\FormVox\Service\IndexService;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\UploadService;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for ResponsePersistenceService.
 *
 * The lock mechanics themselves are pinned by FormLockManagerTest; here the
 * FormLockManager is a light fake that just runs the mutator against a supplied
 * form array, so these tests pin the *response-mutation* behaviour:
 *  - append adds to the responses array and updates the index;
 *  - the append guard can abort (throws propagate, nothing persisted);
 *  - delete removes the matching response, rebuilds the index, and cleans up
 *    that response's uploads;
 *  - delete of an unknown response throws NotFound;
 *  - savePublic REPLACES the whole responses array verbatim (not append) and
 *    rebuilds the index (the red-team's explicit invariant).
 */
class ResponsePersistenceServiceTest extends TestCase
{
    private FormFileLocator $fileLocator;
    private FormLockManager $lockManager;
    private IndexService $indexService;
    private UploadService $uploadService;

    /** The form the fake lock manager mutates; assert against this after. */
    private array $storedForm = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileLocator = $this->createMock(FormFileLocator::class);
        $this->indexService = $this->createMock(IndexService::class);
        $this->uploadService = $this->createMock(UploadService::class);

        // Fake lock manager: runs the mutator against $this->storedForm by
        // reference and returns whatever the mutator returns — exactly the
        // contract the real one provides, minus the storage/locking.
        $this->lockManager = $this->createMock(FormLockManager::class);
        $this->lockManager->method('mutateFormFileWithLock')->willReturnCallback(
            function (File $file, callable $mutator) {
                return $mutator($this->storedForm);
            }
        );

        $file = $this->createMock(File::class);
        $this->fileLocator->method('getFileById')->willReturn($file);
        $this->fileLocator->method('getFileByIdPublic')->willReturn($file);
    }

    private function service(): ResponsePersistenceService
    {
        return new ResponsePersistenceService(
            $this->fileLocator,
            $this->lockManager,
            $this->indexService,
            $this->uploadService
        );
    }

    public function testAppendAddsResponseAndUpdatesIndex(): void
    {
        $this->storedForm = ['responses' => [['id' => 'r1']]];
        $this->indexService->expects($this->once())
            ->method('updateIndex')
            ->with($this->anything(), ['id' => 'r2'], 1); // new index = count-1

        $ret = $this->service()->appendResponse(1, ['id' => 'r2']);

        $this->assertSame(['id' => 'r2'], $ret);
        $this->assertSame([['id' => 'r1'], ['id' => 'r2']], $this->storedForm['responses']);
    }

    public function testAppendInitialisesResponsesArrayWhenAbsent(): void
    {
        $this->storedForm = [];               // no 'responses' key
        $this->service()->appendResponse(1, ['id' => 'r1']);
        $this->assertSame([['id' => 'r1']], $this->storedForm['responses']);
    }

    public function testAppendPublicGuardCanAbort(): void
    {
        $this->storedForm = ['responses' => []];
        $guard = function (array $form): void {
            throw new \RuntimeException('capacity reached');
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/capacity reached/');
        $this->service()->appendResponsePublic(1, ['id' => 'r1'], $guard);
    }

    public function testDeleteRemovesResponseRebuildsIndexAndCleansUploads(): void
    {
        $this->storedForm = ['responses' => [
            ['id' => 'r1'],
            ['id' => 'r2', 'answers' => [['responseId' => 'up-9']]],
        ]];
        $this->indexService->expects($this->once())->method('rebuildIndex');
        // The deleted response's upload must be cleaned up.
        $this->uploadService->expects($this->once())
            ->method('deleteResponseUploads')->with(1, 'up-9');

        $this->service()->deleteResponse(1, 'r2');

        $this->assertSame([['id' => 'r1']], array_values($this->storedForm['responses']));
    }

    public function testDeleteUnknownResponseThrows(): void
    {
        $this->storedForm = ['responses' => [['id' => 'r1']]];
        $this->expectException(NotFoundException::class);
        $this->service()->deleteResponse(1, 'does-not-exist');
    }

    public function testDeleteAllResponsesEmptiesAndCleansAllUploads(): void
    {
        $this->storedForm = ['responses' => [['id' => 'r1'], ['id' => 'r2']]];
        $this->indexService->expects($this->once())->method('rebuildIndex');
        $this->uploadService->expects($this->once())->method('deleteAllUploads')->with(1);

        $this->service()->deleteAllResponses(1);

        $this->assertSame([], $this->storedForm['responses']);
    }

    public function testSavePublicReplacesResponsesVerbatimNotAppend(): void
    {
        // The form on disk already has two responses; savePublic must REPLACE
        // them with the supplied set, not merge/append (#external-API invariant).
        $this->storedForm = ['responses' => [['id' => 'old1'], ['id' => 'old2']], 'title' => 'T'];
        $this->indexService->expects($this->once())->method('rebuildIndex');

        $this->service()->savePublic(1, ['responses' => [['id' => 'new1']]]);

        $this->assertSame([['id' => 'new1']], $this->storedForm['responses']);
        // Other fields on the stored form are untouched.
        $this->assertSame('T', $this->storedForm['title']);
    }

    public function testSavePublicWithNoResponsesKeyClearsResponses(): void
    {
        $this->storedForm = ['responses' => [['id' => 'old1']]];
        $this->service()->savePublic(1, []); // no 'responses' => replace with []
        $this->assertSame([], $this->storedForm['responses']);
    }
}
