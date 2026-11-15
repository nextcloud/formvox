<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\L10n\Translatable;
use OCA\FormVox\Service\LicenseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class LicenseController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private LicenseService $licenseService,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
	}

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}
		return $this->groupManager->isAdmin($user->getUID());
	}

	#[NoCSRFRequired]
	public function getStats(): DataResponse {
		if (!$this->isAdmin()) {
			// TRANSLATORS: Error shown when a non-admin calls an admin-only action
			throw RequestException::forbidden(Translatable::t('Only an administrator can do this.'));
		}

		$stats = $this->licenseService->getStats();
		return new DataResponse(array_merge(['success' => true], $stats));
	}

	public function saveSettings(): DataResponse {
		if (!$this->isAdmin()) {
			// TRANSLATORS: Error shown when a non-admin calls an admin-only action
			throw RequestException::forbidden(Translatable::t('Only an administrator can do this.'));
		}

		$licenseKey = $this->request->getParam('licenseKey');
		if ($licenseKey !== null) {
			$this->licenseService->setLicenseKey((string)$licenseKey);
		}

		return new DataResponse(['success' => true, 'message' => 'License settings saved']);
	}

	public function validate(): DataResponse {
		if (!$this->isAdmin()) {
			// TRANSLATORS: Error shown when a non-admin calls an admin-only action
			throw RequestException::forbidden(Translatable::t('Only an administrator can do this.'));
		}

		$result = $this->licenseService->validateLicense();
		return new DataResponse(['success' => true, 'validation' => $result]);
	}

	public function updateUsage(): DataResponse {
		if (!$this->isAdmin()) {
			// TRANSLATORS: Error shown when a non-admin calls an admin-only action
			throw RequestException::forbidden(Translatable::t('Only an administrator can do this.'));
		}

		$result = $this->licenseService->updateUsage();
		return new DataResponse(['success' => true, 'result' => $result]);
	}
}
