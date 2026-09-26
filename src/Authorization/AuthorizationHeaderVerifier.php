<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Authorization;

use gijsbos\ApiServer\Interfaces\AuthorizationHeaderVerifierInterface;
use gijsbos\Http\Exceptions\UnauthorizedException;

/**
 * AuthorizationHeaderVerifier
 */
class AuthorizationHeaderVerifier implements AuthorizationHeaderVerifierInterface
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

    public function verify(#[\SensitiveParameter] AuthorizationCredentials $credentials) : array
    {
        return match($credentials->scheme)
        {
            AuthorizationScheme::Basic => $this->verifyBasic($credentials),
            AuthorizationScheme::Bearer => $this->verifyBearer($credentials),
        };
    }

    public function setViaBasic(callable $viaBasic) : self
    {
        $this->viaBasic = $viaBasic;
        return $this;
    }

    public function hasViaBasic() : bool
    {
        return $this->viaBasic !== null;
    }

    public function setViaBearer(callable $viaBearer) : self
    {
        $this->viaBearer = $viaBearer;
        return $this;
    }

    public function hasViaBearer() : bool
    {
        return $this->viaBearer !== null;
    }

    /**
     * toResult
     *  The authorization result is an array: the claims of a token payload (an object with toArray, e.g. TokenPayload)
     *  or the array a callable returned. true (verified, nothing to add) results in an empty array.
     */
    private static function toResult(mixed $verifyResult) : array
    {
        if(is_array($verifyResult))
            return $verifyResult;

        if(is_object($verifyResult) && method_exists($verifyResult, "toArray"))
            return $verifyResult->toArray();

        return [];
    }

    private function verifyBasic(#[\SensitiveParameter] AuthorizationCredentials $credentials)
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

        return self::toResult($verifyResult);
    }

    private function verifyBearer(#[\SensitiveParameter] AuthorizationCredentials $credentials)
    {
        $accessToken = $credentials->value;

        if(!is_callable($this->viaBearer))
            throw new UnauthorizedException("schemeNotSupported", "Bearer authorization is not supported");

        $verifyResult = ($this->viaBearer)($accessToken);

        if(!$verifyResult)
            throw new UnauthorizedException("tokenInvalid", "Access token invalid");

        return self::toResult($verifyResult);
    }
}