<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

use Attribute;
use gijsbos\Http\Exceptions\UnauthorizedException;

/**
 * RequiresAuthorization
 *  Use this to create an attribute that checks the token validity.
 * 
 *  !IMPORTANT! THIS IS ONLY A STUB THAT CHECKS IF A TOKEN IS PRESENT, NOT IF IT IS VALID!
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RequiresAuthorization extends ExecuteBeforeRoute
{
    /**
     * extractAuthorizationToken
     *  Note: Apache no longer sends headers by default, use the following to enable headers:
     * 
     *      RewriteCond %{HTTP:Authorization} ^(.*)
     *      RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]
     */
    public static function extractAuthorizationToken() : string
    {
        $authorization = RequestHeader::getHeader('authorization');

        if(!is_string($authorization) || strlen($authorization) == 0)
            throw new UnauthorizedException("tokenRequired", "Token required");

        $authorization = trim($authorization);

        if(!preg_match("/^bearer:?\s+(.+)/i", $authorization, $matches))
            throw new UnauthorizedException("tokenFormatInvalid", "Token invalid, expects bearer token");

        return $matches[1];
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