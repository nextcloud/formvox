<?php

declare(strict_types=1);

namespace OCA\FormVox\Migration;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Repair step to register the .fvform MIME type in the database.
 * This ensures the MIME type is properly registered when the app is installed or updated.
 */
class RegisterMimeType implements IRepairStep
{
    public function getName(): string
    {
        return 'Register FormVox MIME type';
    }

    public function run(IOutput $output): void
    {
        $output->info('Registering .fvform MIME type...');

        // Load the custom MIME type mappings
        $mimeTypeDetector = \OC::$server->getMimeTypeDetector();

        // Register the mapping (extension -> mime type)
        $mimeTypeDetector->registerType('fvform', 'application/x-fvform');

        // Also register the type array for full mapping
        $mimeTypeDetector->registerTypeArray([
            'fvform' => ['application/x-fvform'],
        ]);

        // Update the database with the new MIME type
        // This is equivalent to running: occ maintenance:mimetype:update-db
        // getId() returns existing ID or creates new entry - safe idempotent operation
        $mimeTypeLoader = \OC::$server->getMimeTypeLoader();
        $mimeTypeLoader->getId('application/x-fvform');

        $output->info('FormVox MIME type registered successfully.');
    }
}
