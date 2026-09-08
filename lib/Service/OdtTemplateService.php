<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/**
 * The per-form ODT export template file.
 *
 * A form may carry a custom .odt template used when exporting responses to ODT.
 * It lives in a hidden sibling folder of the .fvform file
 * (`.formvox-templates-{id}/template.odt`), resolved through FormFileLocator so
 * the #90 account selection is honoured (read-only for export, write-capable for
 * upload/delete). Extracted verbatim from FormService.
 */
class OdtTemplateService
{
    private FormFileLocator $fileLocator;

    public function __construct(FormFileLocator $fileLocator)
    {
        $this->fileLocator = $fileLocator;
    }

    /**
     * Get the templates folder for a form
     * Creates it if it doesn't exist
     */
    public function getTemplatesFolder(int $fileId, bool $requireWrite = false): Folder
    {
        // Read-only by default so ODT export can find a template on shares
        // where the reachable account cannot write (#90).
        $formFile = $this->fileLocator->getFileByIdPublic($fileId, $requireWrite);
        $formFolder = $formFile->getParent();
        $templatesFolderName = ".formvox-templates-{$fileId}";

        try {
            $templatesFolder = $formFolder->get($templatesFolderName);
            if (!($templatesFolder instanceof Folder)) {
                throw new \RuntimeException('Templates path is not a folder');
            }
            return $templatesFolder;
        } catch (NotFoundException $e) {
            if (!$requireWrite) {
                throw $e;
            }
            return $formFolder->newFolder($templatesFolderName);
        }
    }

    /**
     * Store an ODT template for a form
     */
    public function storeOdtTemplate(int $fileId, array $uploadedFile): void
    {
        $folder = $this->getTemplatesFolder($fileId, true);

        // Remove existing template if present
        try {
            $existing = $folder->get('template.odt');
            $existing->delete();
        } catch (NotFoundException $e) {
            // No existing template
        }

        $newFile = $folder->newFile('template.odt');
        $newFile->putContent(file_get_contents($uploadedFile['tmp_name']));
    }

    /**
     * Get the ODT template file for a form
     */
    public function getOdtTemplate(int $fileId, bool $requireWrite = false): File
    {
        $folder = $this->getTemplatesFolder($fileId, $requireWrite);
        $file = $folder->get('template.odt');
        if (!($file instanceof File)) {
            throw new NotFoundException('Template not found');
        }
        return $file;
    }

    /**
     * Check if a form has an ODT template
     */
    public function hasOdtTemplate(int $fileId): bool
    {
        try {
            $this->getOdtTemplate($fileId);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete the ODT template for a form
     */
    public function deleteOdtTemplate(int $fileId): void
    {
        try {
            // Resolve through a write-capable account so the delete succeeds.
            $file = $this->getOdtTemplate($fileId, true);
            $file->delete();
        } catch (NotFoundException $e) {
            // No template to delete
        }
    }
}
