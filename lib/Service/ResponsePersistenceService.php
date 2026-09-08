<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\Files\File;
use OCP\Files\NotFoundException;

/**
 * All mutations of a form's `responses` array, performed under the shared lock.
 *
 * Appending a submission, deleting one response or all of them, and replacing
 * the whole array from the external API each go through FormLockManager so they
 * serialize against concurrent writers with a checked write and index rebuild
 * (#3/#4/#8). Extracted from FormService: the CRUD of the form *document* stays
 * there; the CRUD of its *responses* lives here.
 */
class ResponsePersistenceService
{
    private FormFileLocator $fileLocator;
    private FormLockManager $lockManager;
    private IndexService $indexService;
    private UploadService $uploadService;

    public function __construct(
        FormFileLocator $fileLocator,
        FormLockManager $lockManager,
        IndexService $indexService,
        UploadService $uploadService
    ) {
        $this->fileLocator = $fileLocator;
        $this->lockManager = $lockManager;
        $this->indexService = $indexService;
        $this->uploadService = $uploadService;
    }

    /**
     * Append a response to a form with proper file locking
     * Uses exclusive lock to prevent race conditions during concurrent submissions
     */
    public function appendResponse(int $fileId, array $response): array
    {
        $file = $this->fileLocator->getFileById($fileId);

        return $this->appendResponseWithLock($file, $response);
    }

    /**
     * Append a response to a form (public access - no user context needed)
     * Uses same locking mechanism as appendResponse to prevent race conditions
     */
    public function appendResponsePublic(int $fileId, array $response, ?callable $guard = null): array
    {
        $file = $this->fileLocator->getFileByIdPublic($fileId, true);

        return $this->appendResponseWithLock($file, $response, $guard);
    }

    /**
     * Append response with database-based locking to prevent race conditions
     * Uses direct storage access to avoid creating new file versions for each response
     */
    private function appendResponseWithLock(File $file, array $response, ?callable $guard = null): array
    {
        $this->lockManager->mutateFormFileWithLock($file, function (array &$form) use ($response, $guard) {
            // Re-validate limits (capacity / max_responses / duplicates) against
            // the fresh, locked form state before appending, so concurrent
            // submissions can't each pass a pre-lock snapshot check and blow
            // past the limit (#4 TOCTOU). The guard throws to abort the write.
            if ($guard !== null) {
                $guard($form);
            }
            if (!isset($form['responses'])) {
                $form['responses'] = [];
            }
            $form['responses'][] = $response;
            $this->indexService->updateIndex($form, $response, \count($form['responses']) - 1);
        });
        return $response;
    }

    /**
     * Delete a response from a form
     * Uses direct storage access to avoid creating new file versions
     */
    public function deleteResponse(int $fileId, string $responseId): void
    {
        $file = $this->fileLocator->getFileById($fileId);

        // Serialize with the shared lock so a concurrent public submit can't be
        // lost, and so the storage write is return-value-checked (#3, #8).
        $fileUploadResponseIds = $this->lockManager->mutateFormFileWithLock($file, function (array &$form) use ($responseId) {
            $collected = [];
            $found = false;
            foreach ($form['responses'] ?? [] as $index => $response) {
                if (($response['id'] ?? null) === $responseId) {
                    // Collect file upload responseIds from answers before deleting
                    if (isset($response['answers'])) {
                        foreach ($response['answers'] as $answer) {
                            $collected = array_merge($collected, $this->extractFileResponseIds($answer));
                        }
                    }
                    array_splice($form['responses'], $index, 1);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new NotFoundException('Response not found');
            }
            // Rebuild index after deletion
            $this->indexService->rebuildIndex($form);
            return $collected;
        });

        // Delete uploaded files for this response
        foreach (array_unique($fileUploadResponseIds) as $uploadResponseId) {
            $this->uploadService->deleteResponseUploads($fileId, $uploadResponseId);
        }
    }

    /**
     * Delete all responses from a form
     * Uses direct storage access to avoid creating new file versions
     */
    public function deleteAllResponses(int $fileId): void
    {
        $file = $this->fileLocator->getFileById($fileId);

        // Serialize with the shared lock + checked write (#3, #8).
        $this->lockManager->mutateFormFileWithLock($file, function (array &$form) {
            $form['responses'] = [];
            // Rebuild index (will be empty)
            $this->indexService->rebuildIndex($form);
        });

        // Delete all uploaded files for this form
        $this->uploadService->deleteAllUploads($fileId);
    }

    /**
     * Persist a form's responses from an external-API mutation (create/update/
     * delete a response). Used by ExternalApiController, which reads the form,
     * mutates $form['responses'] in memory, and hands the result here to save.
     *
     * The External API replaces the whole responses array, so we apply exactly
     * that under the shared lock (with a checked write and index rebuild), which
     * both makes the write actually happen — it previously called a nonexistent
     * savePublic() and silently lost every write — and serializes it against
     * concurrent public submissions.
     */
    public function savePublic(int $fileId, array $form): void
    {
        $file = $this->fileLocator->getFileByIdPublic($fileId, true);
        $newResponses = $form['responses'] ?? [];

        $this->lockManager->mutateFormFileWithLock($file, function (array &$current) use ($newResponses) {
            $current['responses'] = $newResponses;
            $this->indexService->rebuildIndex($current);
        });
    }

    /**
     * Extract file upload responseIds from an answer
     * Handles both single file and multiple file uploads
     *
     * @param mixed $answer The answer value
     * @return array Array of responseId strings
     */
    private function extractFileResponseIds($answer): array
    {
        if (!is_array($answer)) {
            return [];
        }

        // Single file upload (has responseId directly)
        if (isset($answer['responseId'])) {
            return [$answer['responseId']];
        }

        // Multiple file uploads (array of file objects)
        $responseIds = [];
        foreach ($answer as $item) {
            if (is_array($item) && isset($item['responseId'])) {
                $responseIds[] = $item['responseId'];
            }
        }

        return $responseIds;
    }
}
