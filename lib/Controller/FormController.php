<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Exception\RequestException;
use OCA\FormVox\L10n\Translatable;
use OCA\FormVox\Security\FormAccessDeniedException;
use OCA\FormVox\Security\FormPermission;
use OCA\FormVox\Security\FormSanitizer;
use OCA\FormVox\Security\LinkPasswordPolicy;
use OCA\FormVox\Security\RequireFormPermission;
use OCA\FormVox\Service\FormAccessService;
use OCA\FormVox\Service\FormCreatorService;
use OCA\FormVox\Service\FormRepository;
use OCA\FormVox\Service\FormRevision;
use OCA\FormVox\Service\IntegrationStatus;
use OCA\FormVox\Service\PermissionService;
use OCA\FormVox\Service\ResponsePersistenceService;
use OCA\FormVox\Service\ShareTokenService;
use OCA\FormVox\Service\TemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Share\IManager as IShareManager;
use Psr\Log\LoggerInterface;

class FormController extends Controller {
	public function __construct(
		IRequest $request,
		private FormRepository $formRepository,
		private FormAccessService $access,
		private PermissionService $permissionService,
		private ResponsePersistenceService $responsePersistence,
		private TemplateService $templateService,
		private ShareTokenService $shareTokenService,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private INotificationManager $notificationManager,
		private FormCreatorService $creators,
		private IShareManager $shareManager,
		private FormSanitizer $sanitizer,
		private LinkPasswordPolicy $linkPasswords,
		private IntegrationStatus $integrations,
		private LoggerInterface $logger,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * List all forms accessible to the current user
	 */
	#[NoAdminRequired]
	public function list(): DataResponse {
		return new DataResponse($this->formRepository->listForms());
	}

	/**
	 * Save an existing form as an admin-managed template (admin only). #100
	 */
	#[RequireFormPermission(FormPermission::READ)]
	public function saveAsTemplate(int $fileId, string $title = '', string $description = ''): DataResponse {
		$form = $this->formRepository->loadForm($fileId);
		$entry = $this->templateService->addTemplate(
			// TRANSLATORS: Default name of a form template that has no title
			$title !== '' ? $title : ($form['title'] ?? $this->l->t('Untitled template')),
			$description !== '' ? $description : ($form['description'] ?? ''),
			$form
		);
		return new DataResponse(['template' => $entry]);
	}

	#[NoAdminRequired]
	public function create(string $title, string $path = '', ?string $template = null, array $prefilled = [], bool $notifyOnReady = false, ?string $adminTemplateId = null): DataResponse {
		// Pages and settings only ever come from a template, never from the request.
		unset($prefilled['pages'], $prefilled['settings']);
		// Start from an admin template's structure when one is given (#100).
		if ($adminTemplateId !== null && $adminTemplateId !== '') {
			$tplForm = $this->templateService->getTemplate($adminTemplateId);
			if ($tplForm !== null) {
				if (!empty($tplForm['description']) && !isset($prefilled['description'])) {
					$prefilled['description'] = $tplForm['description'];
				}
				if (!empty($tplForm['questions']) && !isset($prefilled['questions'])) {
					$prefilled['questions'] = $tplForm['questions'];
				}
				foreach (['pages', 'settings'] as $part) {
					if (!empty($tplForm[$part]) && is_array($tplForm[$part])) {
						$prefilled[$part] = $tplForm[$part];
					}
				}
			}
		}

		$result = $this->formRepository->create($title, $path, $template, $prefilled);

		if ($notifyOnReady) {
			$this->sendAiReadyNotification($result, $title);
		}

		return new DataResponse($result, Http::STATUS_CREATED);
	}

	private function sendAiReadyNotification(array $result, string $title): void {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) {
			return;
		}
		$fileId = (int)($result['fileId'] ?? 0);
		if ($fileId <= 0) {
			return;
		}
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new \DateTime())
				->setObject('form', (string)$fileId)
				->setSubject('ai_form_ready', [
					'formTitle' => $title,
					'fileId' => $fileId,
				]);
			$this->notificationManager->notify($notification);
		} catch (\Exception $e) {
			// Notifications are best-effort; never block form creation
		}
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::READ)]
	public function get(int $fileId): DataResponse {
		$file = $this->access->getFile($fileId);
		$form = $this->formRepository->loadForm($fileId);
		$permissions = $this->permissionService->getPermissionsForFile($file);

		return new DataResponse([
			'form' => $this->integrations->annotate($fileId, $this->sanitizer->forUser($form, $permissions)),
			'role' => $this->permissionService->getRoleFromFile($file),
			'permissions' => $permissions,
			'creator' => $this->creators->getCreator($fileId, $form),
		]);
	}

	/**
	 * Creator and whether they still get notifications (FormCreatorService).
	 */
	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::READ)]
	public function getCreator(int $fileId): DataResponse {
		return new DataResponse($this->creators->getCreator($fileId, $this->formRepository->loadForm($fileId)));
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::EDIT)]
	public function update(
		int $fileId,
		?string $title = null,
		?string $description = null,
		?array $questions = null,
		?array $settings = null,
		?array $pages = null,
		?array $permissions = null,
		?array $branding = null,
		?string $descriptionAlign = null,
	): DataResponse {
		$data = array_filter([
			'title' => $title,
			'description' => $description,
			'descriptionAlign' => $descriptionAlign,
			'questions' => $questions,
			'settings' => $settings,
			'pages' => $pages,
			'permissions' => $permissions,
		], fn ($value) => $value !== null);
		// Branding may be sent as an explicit null (use admin defaults); the parameter cannot tell.
		$requestData = json_decode(file_get_contents('php://input'), true) ?? [];
		if (array_key_exists('branding', $requestData)) {
			$data['branding'] = $requestData['branding'];
		}

		if (empty($data)) {
			// TRANSLATORS: Error shown when a save of a form carries nothing to save
			throw RequestException::invalid(Translatable::t('No data provided'));
		}

		$generatedPassword = null;
		if (isset($data['settings'])) {
			$file = $this->access->getFile($fileId);
			// Read like every form: a save in progress is waited for, and a form that stays unreadable fails this
			// save (503) instead of silently dropping its settings.
			$stored = $this->formRepository->readForm($file)['settings'] ?? [];
			$currentSettings = is_array($stored) ? $stored : [];
			$this->requireSharingRightsForChanges($file, $data['settings'], $currentSettings);
			$refusal = $this->applyLinkPasswordPolicy($data['settings'], $currentSettings, $generatedPassword);
			if ($refusal !== null) {
				// Nextcloud's password policy has worded it in the user's language already.
				throw RequestException::invalid($refusal);
			}

			// The share token is server-owned (#135).
			$resolvedSettings = $this->shareTokenService->resolveTokenForUpdate(
				$data['settings'],
				true,
				is_string($currentSettings['public_token'] ?? null) ? $currentSettings['public_token'] : null
			);
			if ($resolvedSettings === null) {
				unset($data['settings']);
			} else {
				$data['settings'] = $resolvedSettings;
			}
		}

		$expected = FormRevision::fromIfMatch($this->request->getHeader('If-Match'));
		// Which save this is, so an editor whose answer got lost can tell whether it landed (review of 3b-4d).
		$saveId = FormRevision::saveId($this->request->getHeader('X-FormVox-Save-Id'));
		$result = ['form' => $this->forCurrentUser($fileId, $this->formRepository->update($fileId, $data, $expected, $saveId))];
		if ($generatedPassword !== null) {
			// Shown once, like the generated password in Nextcloud's own share dialog.
			$result['generatedSharePassword'] = $generatedPassword;
		}
		return new DataResponse($result);
	}

	/**
	 * Writes the shared form file, so it needs edit rights like any other change.
	 */
	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::EDIT)]
	public function setFavorite(int $fileId, bool $favorite): DataResponse {
		$this->formRepository->update($fileId, ['favorite' => $favorite]);
		return new DataResponse(['success' => true, 'favorite' => $favorite]);
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::DELETE)]
	public function delete(int $fileId): DataResponse {
		$this->formRepository->delete($fileId);
		return new DataResponse(['success' => true]);
	}

	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::EDIT)]
	public function rebuildIndex(int $fileId): DataResponse {
		$this->responsePersistence->rebuildIndex($fileId);
		return new DataResponse(['success' => true]);
	}

	/**
	 * Search users and groups for access restriction picker
	 */
	#[NoAdminRequired]
	public function searchSharees(string $search = '', int $limit = 10): DataResponse {
		$users = [];
		foreach ($this->userManager->search($search, $limit) as $user) {
			$users[] = [
				'id' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
			];
		}

		$groups = [];
		foreach ($this->groupManager->search($search, $limit) as $group) {
			$groups[] = [
				'id' => $group->getGID(),
				'displayName' => $group->getDisplayName(),
			];
		}

		return new DataResponse([
			'users' => $users,
			'groups' => $groups,
		]);
	}

	/**
	 * Replace the share link with a fresh one. Its own endpoint, never a side effect of saving (#135).
	 */
	#[NoAdminRequired]
	#[RequireFormPermission(FormPermission::MANAGE_SHARING)]
	public function rotateShareToken(int $fileId): DataResponse {
		$this->requireLinkSharingAllowed();
		$form = $this->formRepository->loadForm($fileId);

		$settings = $this->shareTokenService->rotate($form['settings'] ?? []);

		return new DataResponse(['form' => $this->forCurrentUser($fileId, $this->formRepository->update($fileId, ['settings' => $settings]))]);
	}

	/**
	 * Changing the public link needs full rights; creating a link also needs Nextcloud to allow public links for
	 * this user. What counts as a change is what the save would do (ShareTokenService, FormRepository::update):
	 * a different existing token is ignored anyway (#135), and the password hash is never sent back.
	 *
	 * @throws FormAccessDeniedException
	 */
	private function requireSharingRightsForChanges(File $file, array $incoming, array $current): void {
		$hasLink = self::hasLink($current);
		$creating = !$hasLink && self::asksForLink($incoming);
		$revoking = $hasLink && self::revokesLink($incoming);
		$password = array_key_exists('share_password', $incoming)
			&& (self::isPassword($incoming['share_password']) || !empty($current['share_password_hash']));
		$dates = false;
		foreach (['share_expires_at', 'share_starts_at'] as $key) {
			$dates = $dates || (array_key_exists($key, $incoming) && $incoming[$key] !== ($current[$key] ?? null));
		}
		if (!$creating && !$revoking && !$password && !$dates) {
			return;
		}
		if (!$this->access->allows($file, FormPermission::MANAGE_SHARING)) {
			throw new FormAccessDeniedException();
		}
		if ($creating) {
			$this->requireLinkSharingAllowed();
		}
	}

	/**
	 * Nextcloud's share-password rules (M39): a new password must pass the policy; where passwords are
	 * enforced, a new link gets a generated one and an existing link cannot lose it.
	 * Only these changes are checked, so a link from before enforcement keeps saving.
	 *
	 * @return string|null why the change is refused
	 */
	private function applyLinkPasswordPolicy(array &$incoming, array $current, ?string &$generated): ?string {
		$sentPassword = array_key_exists('share_password', $incoming);
		$newPassword = $sentPassword && is_string($incoming['share_password']) ? $incoming['share_password'] : '';
		if ($newPassword !== '' && ($why = $this->linkPasswords->rejectionFor($newPassword)) !== null) {
			return $why;
		}
		if (!$this->linkPasswords->isEnforced()) {
			return null;
		}
		$hasLink = self::hasLink($current);
		if (!$hasLink && self::asksForLink($incoming) && $newPassword === '') {
			$generated = $this->linkPasswords->generate();
			$incoming['share_password'] = $generated;
			return null;
		}
		if ($hasLink && !self::revokesLink($incoming) && $sentPassword && $newPassword === '') {
			return 'A password is required for public links';
		}
		return null;
	}

	private static function hasLink(array $settings): bool {
		return is_string($settings['public_token'] ?? null) && $settings['public_token'] !== '';
	}

	/** The value sent is only an intent marker, the token is minted on the server: anything but null or '' asks. */
	private static function asksForLink(array $settings): bool {
		return array_key_exists('public_token', $settings) && !self::revokesLink($settings);
	}

	private static function revokesLink(array $settings): bool {
		return array_key_exists('public_token', $settings) && ($settings['public_token'] === null || $settings['public_token'] === '');
	}

	/** What FormRepository::update hashes; anything else sent as share_password removes the password. */
	private static function isPassword(mixed $value): bool {
		return is_string($value) && $value !== '';
	}

	private function forCurrentUser(int $fileId, array $form): array {
		return $this->integrations->annotate($fileId, $this->sanitizer->forUser($form, $this->permissionService->getPermissionsForFile($this->access->getFile($fileId))));
	}

	/**
	 * @throws FormAccessDeniedException
	 */
	private function requireLinkSharingAllowed(): void {
		$user = $this->userSession->getUser();
		if ($user === null
			|| !$this->shareManager->shareApiAllowLinks($user)
			|| $this->shareManager->sharingDisabledForUser($user->getUID())) {
			// TRANSLATORS: Error shown when the admin does not allow public links for this user
			throw RequestException::turnedOff(Translatable::t('Public links are not allowed'));
		}
	}

}
