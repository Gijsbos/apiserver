<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Authentication;

use gijsbos\ApiServer\Server;
use gijsbos\Http\Exceptions\UnauthorizedException;

/**
 * AuthenticationVerifier
 */
class AuthenticationVerifier
{
    public function __construct(
        /**
         * @var null|callable
         */
        private $viaBasic = null,

        /**
         * @var null|callable
         */
        private $viaBearer = null
    )
    { }

    public function verify(#[\SensitiveParameter] AuthenticationCredentials $credentials) : mixed
    {
        return match($credentials->scheme)
        {
            AuthenticationScheme::Basic => $this->verifyBasic($credentials),
            AuthenticationScheme::Bearer => $this->verifyBearer($credentials),
        };
    }

    public function hasViaBasic()
    {
        return $this->viaBasic !== null;
    }

    public function hasViaBearer()
    {
        return $this->viaBearer !== null;
    }

    private function verifyBasic(#[\SensitiveParameter] AuthenticationCredentials $credentials): mixed
    {
        $decoded = base64_decode($credentials->value, true);

        if($decoded === false || !str_contains($decoded, ':'))
            throw new UnauthorizedException("credentialsFormatInvalid", "Basic authorization credentials are not valid base64 encoded \"username:password\"");

        [$username, $password] = explode(':', $decoded, 2);

        if(!is_callable($this->viaBasic))
            throw new UnauthorizedException("schemeNotSupported", "Basic authorization is not supported");

        $verifyResult = ($this->viaBasic)($username, $password);

        if(!$verifyResult)
            throw new UnauthorizedException("credentialsInvalid", "Username or password is invalid");

        return $verifyResult;
    }

    private function verifyBearer(#[\SensitiveParameter] AuthenticationCredentials $credentials): mixed
    {
        $accessToken = $credentials->value;

        if(!is_callable($this->viaBearer))
            throw new UnauthorizedException("schemeNotSupported", "Bearer authorization is not supported");

        $verifyResult = ($this->viaBearer)($accessToken);

        if(!$verifyResult)
            throw new UnauthorizedException("tokenInvalid", "Access token invalid");

        return $verifyResult;
    }
}