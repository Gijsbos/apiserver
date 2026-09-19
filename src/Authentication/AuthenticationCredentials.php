<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Authentication;

/**
 * AuthenticationCredentials
 */
final class AuthenticationCredentials
{
    public function __construct(
        public readonly AuthenticationScheme $scheme,
        #[\SensitiveParameter]
        public readonly string $value,
    )
    { }
}