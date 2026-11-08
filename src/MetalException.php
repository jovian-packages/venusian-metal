<?php

declare(strict_types=1);

namespace Jovian\Engines\Metal;

use NSError;
use NSException;
use RuntimeException;

/** A Metal call that failed: the NSError ext-metal handed back, or the NSException Metal raised. */
final class MetalException extends RuntimeException
{
    public static function fromError(?NSError $error): self
    {
        return is_null($error)
            ? new self('Metal call failed')
            : new self($error->localizedDescription(), $error->code());
    }

    public static function fromException(NSException $exception): self
    {
        return new self($exception->getMessage(), 0, $exception);
    }
}
