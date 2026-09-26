<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Interfaces;

use gijsbos\ApiServer\Authorization\AuthorizationCredentials;

/**
 * AuthorizationHeaderVerifierInterface
 *  Verifies the credentials of the Authorization header. verify() throws to
 *  deny and returns the authorization result (e.g. token claims) to allow.
 */
interface AuthorizationHeaderVerifierInterface
{
    public function verify(#[\SensitiveParameter] AuthorizationCredentials $credentials) : array;
}
