<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Authentication;

use gijsbos\Http\Exceptions\UnauthorizedException;

/**
 * AuthenticationVerifier
 */
class AuthenticationVerifier
{
    public static $viaBasic = null;
    public static $viaBearer = null;

    public function __construct(

    )
    { }

    public function verify(#[\SensitiveParameter] AuthenticationCredentials $credentials) : void
    {
        match($credentials->scheme)
        {
            AuthenticationScheme::Basic => $this->verifyBasic($credentials),
            AuthenticationScheme::Bearer => $this->verifyBearer($credentials),
        };
    }

    private function verifyBasic(#[\SensitiveParameter] AuthenticationCredentials $credentials) : void
    {
        $decoded = base64_decode($credentials->value, true);

        if($decoded === false || !str_contains($decoded, ':'))
            throw new UnauthorizedException("credentialsFormatInvalid", "Basic authorization credentials are not valid base64 encoded \"username:password\"");

        [$username, $password] = explode(':', $decoded, 2);

        if(!is_callable(self::$viaBasic))
            throw new UnauthorizedException("schemeNotSupported", "Basic authorization is not supported");

        if(!(self::$viaBasic)($username, $password))
            throw new UnauthorizedException("credentialsInvalid", "Username or password is invalid");
    }

    private function verifyBearer(#[\SensitiveParameter] AuthenticationCredentials $credentials) : void
    {
        $accessToken = $credentials->value;

        if(!is_callable(self::$viaBearer))
            throw new UnauthorizedException("schemeNotSupported", "Bearer authorization is not supported");

        if(!(self::$viaBearer)($accessToken))
            throw new UnauthorizedException("tokenInvalid", "Access token invalid");
    }
}