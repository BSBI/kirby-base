<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\panel;

use Kirby\Exception\InvalidArgumentException;

/**
 * Refuses a panel save that would rename or remove a question whose key
 * stored responses use. The message is shown to the editor.
 */
final class FormKeyLockedException extends InvalidArgumentException
{
}
