<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Authorization;

/**
 * AuthorizationCredentials
 */
final class AuthorizationCredentials
{
    public function __construct(
        public readonly AuthorizationScheme $scheme, #[\SensitiveParameter] public readonly string $value,
    )
    { }
}