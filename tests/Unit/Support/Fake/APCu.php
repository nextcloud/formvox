<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit\Support\Fake;

/** APCu as Nextcloud names it: web and cron each have their own, so FormLock must not keep its key there. */
class APCu extends Redis {
}
