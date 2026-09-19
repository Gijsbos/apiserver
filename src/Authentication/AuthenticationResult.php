<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Authentication;

/**
 * AuthenticationResult
 */
final class AuthenticationResult
{
    public function __construct(
        #[\SensitiveParameter]
        private readonly mixed $data,
    )
    { }

    public function getData()
    {
        return $this->data;
    }
}