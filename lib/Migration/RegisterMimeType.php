<?php

declare(strict_types=1);

namespace OCA\FormVox\Migration;

use OCP\App\IAppManager;
use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Repair step to register the .fvform MIME type.
 * This ensures the MIME type is properly registered when the app is installed or updated,
 * including the icon mapping for the Files app.
 */
class RegisterMimeType implements IRepairStep {
	public function __construct(
		private IAppManager $appManager,
		private IMimeTypeLoader $mimeTypeLoader,
		private IDBConnection $db,
		private IAppConfig $appConfig,
	) {
	}

	public function getName(): string {
		return 'Register FormVox MIME type';
	}

	public function run(IOutput $output): void {
		$output->info('Registering .fvform MIME type...');

		// Note: Do NOT call registerType()/registerTypeArray() on the detector here.
		// Doing so populates MimeTypeDetector::$mimeTypes before loadMappings() runs,
		// causing loadMappings() to skip loading core defaults and breaking all mimetypes.
		// The appinfo/mimetypemapping.json and config/mimetypemapping.json are sufficient.

		// Register MIME type in database
		$this->mimeTypeLoader->getId('application/x-fvform');

		// Update core config files for the extension and the fallback icon
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
	private function updateMimeTypeMappingConfig(IOutput $output): void {
		$mappingFile = \OC::$configDir . 'mimetypemapping.json';
		$mapping = $this->readConfigJson($mappingFile, $output);
		if ($mapping === null || isset($mapping['fvform'])) {
			return;
		}
		$mapping['fvform'] = ['application/x-fvform'];
		$this->writeConfigJson($mappingFile, $mapping, 'Added fvform to mimetypemapping.json', $output);
	}

	/**
	 * Where Nextcloud cannot show a form's preview, it falls back to core's plain file icon. The 'formvox' alias that
	 * earlier versions set pointed at an icon core does not have, which left an empty space.
	 */
	private function updateMimeTypeAliasesConfig(IOutput $output): void {
		$aliasesFile = \OC::$configDir . 'mimetypealiases.json';
		$aliases = $this->readConfigJson($aliasesFile, $output);
		if ($aliases === null) {
			return;
		}
		$alias = $aliases['application/x-fvform'] ?? null;
		if ($alias !== null && $alias !== 'formvox') {
			return;
		}
		// After `occ maintenance:mimetype:update-js`, core's list holds the alias; the integrity check wants them equal.
		$wanted = $this->aliasInCoreList() ?? 'file';
		if ($alias === $wanted) {
			return;
		}
		$aliases['application/x-fvform'] = $wanted;
		$this->writeConfigJson($aliasesFile, $aliases, "Set the fvform alias in mimetypealiases.json to $wanted", $output);
	}

	private function aliasInCoreList(): ?string {
		$list = @file_get_contents(\OC::$SERVERROOT . '/core/js/mimetypelist.js');
		if ($list !== false && preg_match('#"application/x-fvform":\s*"([^"]+)"#', $list, $match) === 1) {
			return $match[1];
		}
		return null;
	}

	/**
	 * @return array<string, mixed>|null null when the file is there but cannot be read: it is left alone, so an
	 *                                   admin's other entries are not lost
	 */
	private function readConfigJson(string $file, IOutput $output): ?array {
		if (!file_exists($file)) {
			return [];
		}
		$content = @file_get_contents($file);
		if ($content !== false && trim($content) === '') {
			return [];
		}
		$data = $content === false ? null : json_decode($content, true);
		if (!is_array($data)) {
			$output->warning("Could not read $file; FormVox left it as it is");
			return null;
		}
		return $data;
	}

	private function writeConfigJson(string $file, array $data, string $done, IOutput $output): void {
		if (@file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT)) === false) {
			$output->warning("Could not write $file");
			return;
		}
		$output->info($done);
	}

	/**
	 * Remove the filetype icon that older versions copied into Nextcloud core.
	 *
	 * Writing into the signed core/img/filetypes directory triggers a
	 * code-integrity EXTRA_FILE error (and can block Nextcloud AIO startup) —
	 * see #128. The icon is not needed there: FormPreview shows it.
	 * This deletes any leftover file so existing installs self-heal on upgrade.
	 */
	private function removeStaleCoreIcon(IOutput $output): void {
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
	private function updateFilecacheMimeTypes(IOutput $output): void {
		$mimeTypeId = $this->mimeTypeLoader->getId('application/x-fvform');
		$folderTypeId = $this->mimeTypeLoader->getId('httpd/unix-directory');

		$qb = $this->db->getQueryBuilder();

		// Files only: a folder named like a form stays a folder (7a, 3a-6).
		$qb->update('filecache')
			->set('mimetype', $qb->createNamedParameter($mimeTypeId))
			->where($qb->expr()->like('name', $qb->createNamedParameter('%.fvform')))
			->andWhere($qb->expr()->neq('mimetype', $qb->createNamedParameter($mimeTypeId)))
			->andWhere($qb->expr()->neq('mimetype', $qb->createNamedParameter($folderTypeId)));

		$updated = $qb->executeStatement();
		if ($updated > 0) {
			$output->info("Updated MIME type for {$updated} existing .fvform files");
		}

		// Such a folder an earlier version marked as a form is a folder again; its type part was left as it was. Once:
		// the name is matched on the whole file cache.
		if ($this->appConfig->getValueBool('formvox', 'folders_named_like_forms_repaired')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('filecache')
			->set('mimetype', $qb->createNamedParameter($folderTypeId))
			->where($qb->expr()->like('name', $qb->createNamedParameter('%.fvform')))
			->andWhere($qb->expr()->eq('mimetype', $qb->createNamedParameter($mimeTypeId)))
			->andWhere($qb->expr()->eq('mimepart', $qb->createNamedParameter($this->mimeTypeLoader->getId('httpd'))));
		$repaired = $qb->executeStatement();
		if ($repaired > 0) {
			$output->info("Restored {$repaired} folders named like a form");
		}
		$this->appConfig->setValueBool('formvox', 'folders_named_like_forms_repaired', true);
	}
}
