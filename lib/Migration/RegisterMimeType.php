<?php

declare(strict_types=1);

namespace OCA\FormVox\Migration;

use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Repair step to register the .fvform MIME type.
 * This ensures the MIME type is properly registered when the app is installed or updated,
 * including the icon mapping for the Files app.
 */
class RegisterMimeType implements IRepairStep
{
    private IAppManager $appManager;

    public function __construct(IAppManager $appManager)
    {
        $this->appManager = $appManager;
    }

    public function getName(): string
    {
        return 'Register FormVox MIME type';
    }

    public function run(IOutput $output): void
    {
        $output->info('Registering .fvform MIME type...');

        // Register MIME type in detector
        $mimeTypeDetector = \OC::$server->getMimeTypeDetector();
        $mimeTypeDetector->registerType('fvform', 'application/x-fvform');
        $mimeTypeDetector->registerTypeArray([
            'fvform' => ['application/x-fvform'],
        ]);

        // Register MIME type in database
        $mimeTypeLoader = \OC::$server->getMimeTypeLoader();
        $mimeTypeLoader->getId('application/x-fvform');

        // Update core config files for icon mapping
        $this->updateMimeTypeMappingConfig($output);
        $this->updateMimeTypeAliasesConfig($output);
        $this->copyFileTypeIcon($output);

        // Update filecache for existing .fvform files
        $this->updateFilecacheMimeTypes($output);

        $output->info('FormVox MIME type registered successfully.');
    }

    /**
     * Add fvform to Nextcloud's mimetypemapping.json config
     */
    private function updateMimeTypeMappingConfig(IOutput $output): void
    {
        $configDir = \OC::$configDir;
        $mappingFile = $configDir . 'mimetypemapping.json';

        $mapping = [];
        if (file_exists($mappingFile)) {
            $content = file_get_contents($mappingFile);
            $mapping = json_decode($content, true) ?? [];
        }

        if (!isset($mapping['fvform'])) {
            $mapping['fvform'] = ['application/x-fvform'];
            file_put_contents($mappingFile, json_encode($mapping, JSON_PRETTY_PRINT));
            $output->info('Added fvform to mimetypemapping.json');
        }
    }

    /**
     * Add fvform alias to Nextcloud's mimetypealiases.json config
     */
    private function updateMimeTypeAliasesConfig(IOutput $output): void
    {
        $configDir = \OC::$configDir;
        $aliasesFile = $configDir . 'mimetypealiases.json';

        $aliases = [];
        if (file_exists($aliasesFile)) {
            $content = file_get_contents($aliasesFile);
            $aliases = json_decode($content, true) ?? [];
        }

        if (!isset($aliases['application/x-fvform'])) {
            $aliases['application/x-fvform'] = 'formvox';
            file_put_contents($aliasesFile, json_encode($aliases, JSON_PRETTY_PRINT));
            $output->info('Added fvform alias to mimetypealiases.json');
        }
    }

    /**
     * Copy the FormVox filetype icon to Nextcloud core
     */
    private function copyFileTypeIcon(IOutput $output): void
    {
        $appPath = $this->appManager->getAppPath('formvox');
        $sourceIcon = $appPath . '/img/filetypes/application-x-fvform.svg';
        $targetDir = \OC::$SERVERROOT . '/core/img/filetypes';
        $targetIcon = $targetDir . '/formvox.svg';

        if (file_exists($sourceIcon) && !file_exists($targetIcon)) {
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            copy($sourceIcon, $targetIcon);
            $output->info('Copied FormVox filetype icon to core');
        }
    }

    /**
     * Update existing .fvform files in filecache to use correct MIME type
     */
    private function updateFilecacheMimeTypes(IOutput $output): void
    {
        $mimeTypeLoader = \OC::$server->getMimeTypeLoader();
        $mimeTypeId = $mimeTypeLoader->getId('application/x-fvform');

        $db = \OC::$server->getDatabaseConnection();
        $qb = $db->getQueryBuilder();

        $qb->update('filecache')
            ->set('mimetype', $qb->createNamedParameter($mimeTypeId))
            ->where($qb->expr()->like('name', $qb->createNamedParameter('%.fvform')))
            ->andWhere($qb->expr()->neq('mimetype', $qb->createNamedParameter($mimeTypeId)));

        $updated = $qb->executeStatement();
        if ($updated > 0) {
            $output->info("Updated MIME type for {$updated} existing .fvform files");
        }
    }
}
