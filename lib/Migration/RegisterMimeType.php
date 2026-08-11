<?php

declare(strict_types=1);

namespace OCA\FormVox\Migration;

use OCP\App\IAppManager;
use OCP\Files\IMimeTypeLoader;
use OCP\IDBConnection;
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
    private IMimeTypeLoader $mimeTypeLoader;
    private IDBConnection $db;

    public function __construct(IAppManager $appManager, IMimeTypeLoader $mimeTypeLoader, IDBConnection $db)
    {
        $this->appManager = $appManager;
        $this->mimeTypeLoader = $mimeTypeLoader;
        $this->db = $db;
    }

    public function getName(): string
    {
        return 'Register FormVox MIME type';
    }

    public function run(IOutput $output): void
    {
        $output->info('Registering .fvform MIME type...');

        // Note: Do NOT call registerType()/registerTypeArray() on the detector here.
        // Doing so populates MimeTypeDetector::$mimeTypes before loadMappings() runs,
        // causing loadMappings() to skip loading core defaults and breaking all mimetypes.
        // The appinfo/mimetypemapping.json and config/mimetypemapping.json are sufficient.

        // Register MIME type in database
        $this->mimeTypeLoader->getId('application/x-fvform');

        // Update core config files for icon mapping
        $this->updateMimeTypeMappingConfig($output);
        $this->updateMimeTypeAliasesConfig($output);
        // Clean up the icon we used to copy into signed core (integrity error #128).
        $this->removeStaleCoreIcon($output);

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
     * Remove the filetype icon that older versions copied into Nextcloud core.
     *
     * Writing into the signed core/img/filetypes directory triggers a
     * code-integrity EXTRA_FILE error (and can block Nextcloud AIO startup) —
     * see #128. The icon is not needed there: css/filetypes.css already styles
     * .fvform files from the app's own img directory, which is integrity-safe.
     * This deletes any leftover file so existing installs self-heal on upgrade.
     */
    private function removeStaleCoreIcon(IOutput $output): void
    {
        $staleIcon = \OC::$SERVERROOT . '/core/img/filetypes/formvox.svg';
        if (file_exists($staleIcon)) {
            if (@unlink($staleIcon)) {
                $output->info('Removed stale FormVox icon from core (fixes integrity check #128)');
            } else {
                $output->warning('Could not remove ' . $staleIcon . ' — delete it manually and run: occ integrity:check-core');
            }
        }
    }

    /**
     * Update existing .fvform files in filecache to use correct MIME type
     */
    private function updateFilecacheMimeTypes(IOutput $output): void
    {
        $mimeTypeId = $this->mimeTypeLoader->getId('application/x-fvform');

        $qb = $this->db->getQueryBuilder();

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
