<?php

declare(strict_types=1);

namespace Kipchak\Identity;

/**
 * A consumer or tenant source that cannot work, raised while the API boots rather than on a request.
 *
 * The message names the key path within the sources ("consumer[1].name must be ..."). The package that read
 * the config prefixes its file name with inConfig(), so the error says where to look.
 */
final class InvalidConfiguration extends \InvalidArgumentException
{
    public static function at(string $path, string $problem): self
    {
        return new self("{$path} {$problem}");
    }

    public function inConfig(string $configName): self
    {
        return new self("{$configName}: {$this->getMessage()}", 0, $this);
    }
}
