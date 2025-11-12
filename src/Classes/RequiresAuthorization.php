<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

use Attribute;
use gijsbos\Http\Exceptions\UnauthorizedException;

/**
 * RequiresAuthorization
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RequiresAuthorization extends ExecuteBeforeRoute
{
    /**
     * extractAuthorizationToken
     */
    public static function extractAuthorizationToken() : string
    {
        $authorization = RequestHeader::getHeader('authorization');

        if($authorization === null)
            throw new UnauthorizedException("tokenRequired", "Access token required");

        $authorization = trim($authorization);

        if(($p = stripos($authorization, "bearer ")) !== false)
        {
            return substr($authorization, $p + strlen("bearer "));
        }
        else
        {
            return $authorization;
        }
    }

    /**
     * __construct
     */
    public function __construct(mixed $verifyTokenCallback = null)
    {
        parent::__construct(function(Route $route) use ($verifyTokenCallback)
        {
            $authorizationToken = self::extractAuthorizationToken();

            if(is_callable($verifyTokenCallback))
                $verifyTokenCallback($authorizationToken, $route);
        });
    }
}