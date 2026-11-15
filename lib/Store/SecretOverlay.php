<?php

declare(strict_types=1);

namespace OCA\FormVox\Store;

/**
 * Puts a stored form's secrets into its settings when it is read, and takes them out again before it is written, so
 * the file never holds them and every reader still finds them in the settings. Forms not in the database pass as they
 * are.
 */
class SecretOverlay {
	public function __construct(
		private FormStore $forms,
		private SecretStore $secrets,
	) {
	}

	/** @param string|null $uuid the one in the file, to find a row that lost the file's id */
	public function stored(int $fileId, ?string $uuid = null): ?StoredForm {
		return $this->forms->resolve($fileId, $uuid);
	}

	public function add(?StoredForm $stored, array $form): array {
		if ($stored === null) {
			return $form;
		}
		$settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
		// What a file says about them counts for nothing: the database holds them.
		$settings = array_diff_key($settings, array_flip(SecretStore::KEYS)) + $this->secrets->load($stored->id);
		if ($settings !== [] || array_key_exists('settings', $form)) {
			$form['settings'] = $settings;
		}
		return $form;
	}

	/** @return array<string, mixed> */
	public static function secretsOf(array $form): array {
		$settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
		return array_intersect_key($settings, array_flip(SecretStore::KEYS));
	}

	/**
	 * The form for the file, and its changed secrets in two steps around the write: what was taken away before it, so a
	 * revoked link or key stays revoked when the write fails; what is new or changed only after it, so it does not count
	 * when the change it came with was not written.
	 *
	 * @param array<string, mixed> $before the secrets the form was read with: unchanged ones are not written again
	 * @return array{0: array, 1: (callable(): void)|null, 2: (callable(): void)|null} the form, before the write, after it
	 */
	public function split(?StoredForm $stored, array $form, array $before = []): array {
		if ($stored === null) {
			return [$form, null, null];
		}
		$secrets = self::secretsOf($form);
		if (array_key_exists('settings', $form) && is_array($form['settings'])) {
			$form['settings'] = array_diff_key($form['settings'], $secrets);
		}
		if ($secrets == $before) {
			return [$form, null, null];
		}
		$kept = self::keptUntilWritten($before, $secrets);
		return [
			$form,
			$kept == $before ? null : fn () => $this->secrets->save($stored->id, $kept),
			$kept == $secrets ? null : fn () => $this->secrets->save($stored->id, $secrets),
		];
	}

	/**
	 * What stays in force until the write is done: every secret that is in both, in its old version; of a list the
	 * items still there by id. Only what is gone altogether is taken away first.
	 *
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<string, mixed>
	 */
	private static function keptUntilWritten(array $before, array $after): array {
		$kept = [];
		foreach ($before as $key => $value) {
			if ($key === 'share_password_hash') {
				// Without it the link is open: a changed or removed password takes effect only once written.
				$kept[$key] = $value;
				continue;
			}
			if (!array_key_exists($key, $after)) {
				continue;
			}
			if ($after[$key] == $value) {
				$kept[$key] = $value;
			} elseif (is_array($value) && is_array($after[$key]) && array_is_list($value)) {
				$items = array_values(array_filter($value, fn ($item) => self::stillThere($item, $after[$key])));
				if ($items !== []) {
					$kept[$key] = $items;
				}
			}
		}
		return $kept;
	}

	/**
	 * An item changed in place (same id) is still there; one that is gone is not.
	 */
	private static function stillThere(mixed $item, array $list): bool {
		$id = is_array($item) && is_scalar($item['id'] ?? null) ? $item['id'] : null;
		foreach ($list as $other) {
			if ($other == $item || ($id !== null && is_array($other) && ($other['id'] ?? null) === $id)) {
				return true;
			}
		}
		return false;
	}
}
