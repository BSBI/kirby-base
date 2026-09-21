<?php

declare(strict_types=1);

namespace BSBI\WebBase\storage;

use RuntimeException;

/**
 * A stored document could not be written, read, listed or deleted, or the
 * storage could not be built from its configuration.
 */
final class DocumentStorageException extends RuntimeException
{
}
