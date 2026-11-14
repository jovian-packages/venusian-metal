<?php

namespace Jovian\Engines\Metal;

use NSError;
use NSException;
use Surface\Contracts\Graphics\GraphicsException;
use Throwable;

/** What Metal refused or failed at, in the metal engine's words. */
class MetalException extends GraphicsException
{
    public static function because(string $what, ?Throwable $previous = null): self
    {
        return new self("metal: {$what}", 0, $previous);
    }

    /** $what, with the NSError or NSException Metal gave as the reason. */
    public static function from(string $what, NSError|NSException|null $reason): self
    {
        return match (true) {
            $reason instanceof NSError => new self("metal: {$what}: {$reason->localizedDescription()}", $reason->code()),
            $reason instanceof NSException => new self("metal: {$what}: {$reason->getMessage()}", 0, $reason),
            default => new self("metal: {$what}."),
        };
    }
}
