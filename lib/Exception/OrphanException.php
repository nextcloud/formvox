<?php

declare(strict_types=1);

namespace OCA\FormVox\Exception;

/** A form or file an administrator named that cannot be removed or bound; the message is written for them. */
final class OrphanException extends \InvalidArgumentException {
}
