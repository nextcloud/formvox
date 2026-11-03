<?php

declare(strict_types=1);

namespace OCA\FormVox\Service;

use OCP\IL10N;
use OCP\Security\ISecureRandom;

/**
 * Unique response links: extra share tokens for one form, each limited to a
 * number of responses (typically one), so an owner can hand out e.g. 250
 * single-use links.
 *
 * The links live in the top-level `response_links` array of the form file,
 * deliberately outside `settings`: FormRepository::update() replaces settings
 * wholesale on every save and does not write this key at all, so a generic
 * form save can never drop or alter a link.
 *
 * Each link counts its own `uses`. The count is checked and incremented by
 * consume() inside the response write lock (see ResponseService guards), so
 * concurrent submissions can't use a single-use link twice.
 *
 * Responses must not be traceable to a link: a response stores nothing about
 * the link it came through, and the link stores only how often it was used —
 * no response id, no timestamp. Consequently deleting responses never makes a
 * link usable again.
 */
class ResponseLinkService {
	public const FORM_KEY = 'response_links';

	/** Upper bound for one generate call, to keep the form file reasonable. */
	public const MAX_PER_BATCH = 1000;
	/** Upper bound for the total number of links on one form. */
	public const MAX_TOTAL = 5000;

	private FormFileLocator $fileLocator;
	private FormLockManager $lockManager;
	private ShareTokenService $shareTokenService;
	private ISecureRandom $secureRandom;
	private IL10N $l;

	public function __construct(
		FormFileLocator $fileLocator,
		FormLockManager $lockManager,
		ShareTokenService $shareTokenService,
		ISecureRandom $secureRandom,
		IL10N $l,
	) {
		$this->fileLocator = $fileLocator;
		$this->lockManager = $lockManager;
		$this->shareTokenService = $shareTokenService;
		$this->secureRandom = $secureRandom;
		$this->l = $l;
	}

	/**
	 * Find the response link matching $token (timing-safe), or null.
	 */
	public function findByToken(array $form, string $token): ?array {
		$match = null;
		foreach ($form[self::FORM_KEY] ?? [] as $link) {
			$stored = $link['token'] ?? null;
			// No early exit, so the time taken doesn't reveal which link matched.
			if (is_string($stored) && $stored !== '' && hash_equals($stored, $token)) {
				$match = $link;
			}
		}
		return $match;
	}

	public function isAvailable(array $form, array $link): bool {
		// Re-read the link from $form: it may have been used or deleted since
		// the caller looked it up (the guard runs against the fresh, locked form).
		$index = $this->indexOf($form, $link);
		if ($index === null) {
			return false;
		}
		$current = $form[self::FORM_KEY][$index];
		return (int)($current['uses'] ?? 0) < (int)($current['max_uses'] ?? 1);
	}

	/**
	 * Throw when the link can no longer be used to respond.
	 *
	 * @throws \RuntimeException
	 */
	public function assertAvailable(array $form, array $link): void {
		if (!$this->isAvailable($form, $link)) {
			throw new \RuntimeException($this->unavailableMessage());
		}
	}

	/**
	 * Use up one response of the link, or throw when it has none left.
	 * Call it on the fresh form under the response write lock.
	 *
	 * @throws \RuntimeException
	 */
	public function consume(array &$form, array $link): void {
		$this->assertAvailable($form, $link);
		$index = $this->indexOf($form, $link);
		$form[self::FORM_KEY][$index]['uses'] = (int)($form[self::FORM_KEY][$index]['uses'] ?? 0) + 1;
	}

	private function indexOf(array $form, array $link): ?int {
		$linkId = $link['id'] ?? null;
		foreach ($form[self::FORM_KEY] ?? [] as $index => $candidate) {
			if (is_string($linkId) && ($candidate['id'] ?? null) === $linkId) {
				return $index;
			}
		}
		return null;
	}

	public function unavailableMessage(): string {
		// TRANSLATORS: Shown when a unique (e.g. single-use) response link was already used
		return $this->l->t('This link has already been used');
	}

	/**
	 * The links of a form as shown to the owner, with their usage.
	 *
	 * @return list<array>
	 */
	public function listWithUsage(array $form): array {
		$out = [];
		foreach ($form[self::FORM_KEY] ?? [] as $link) {
			$link['uses'] = (int)($link['uses'] ?? 0);
			$out[] = $link;
		}
		return $out;
	}

	/**
	 * Generate $count new links that each accept $maxUses responses.
	 *
	 * @return list<array> the created links
	 * @throws \InvalidArgumentException on out-of-range input
	 */
	public function generate(int $fileId, int $count, int $maxUses, string $label, string $userId): array {
		if ($count < 1 || $count > self::MAX_PER_BATCH) {
			throw new \InvalidArgumentException(
				$this->l->t('You can create between 1 and %d links at a time', [self::MAX_PER_BATCH])
			);
		}
		if ($maxUses < 1) {
			throw new \InvalidArgumentException($this->l->t('Each link must allow at least one response'));
		}
		$label = mb_substr(trim($label), 0, 100);

		$file = $this->fileLocator->getFileById($fileId);
		return $this->lockManager->mutateFormFileWithLock($file, function (array &$form) use ($count, $maxUses, $label, $userId) {
			$links = $form[self::FORM_KEY] ?? [];
			if (count($links) + $count > self::MAX_TOTAL) {
				throw new \InvalidArgumentException(
					$this->l->t('A form can have at most %d unique links', [self::MAX_TOTAL])
				);
			}

			$now = (new \DateTime())->format(\DateTime::ATOM);
			$created = [];
			for ($i = 0; $i < $count; $i++) {
				$created[] = [
					'id' => $this->secureRandom->generate(16, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS),
					'token' => $this->shareTokenService->generateToken(),
					'label' => $label,
					'max_uses' => $maxUses,
					'uses' => 0,
					'created_at' => $now,
					'created_by' => $userId,
				];
			}
			$form[self::FORM_KEY] = array_merge($links, $created);
			return $created;
		});
	}

	/**
	 * Delete links by id. Responses already submitted through them are kept.
	 * (Responses are never linked to a link, so there is nothing to clean up.)
	 *
	 * @param list<string>|null $linkIds the links to delete, or null for all
	 * @return int the number of links deleted
	 */
	public function delete(int $fileId, ?array $linkIds): int {
		$file = $this->fileLocator->getFileById($fileId);
		return $this->lockManager->mutateFormFileWithLock($file, function (array &$form) use ($linkIds) {
			$links = $form[self::FORM_KEY] ?? [];
			$kept = $linkIds === null
				? []
				: array_values(array_filter($links, fn ($link) => !in_array($link['id'] ?? null, $linkIds, true)));
			$form[self::FORM_KEY] = $kept;
			return count($links) - count($kept);
		});
	}
}
