<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Authorization;

use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\Http\Exceptions\UnauthorizedException;

/**
 * AuthorizationHeaderParser
 *
 *  Note: Authorization headers are stripped by Apache for CGI/FastCGI SAPIs
 *  (RFC 3875 §4.1.18), not just "newer Apache". Fix with either:
 *      CGIPassAuth On   (Apache 2.4.13+)
 *  or the mod_rewrite workaround:
 *      RewriteCond %{HTTP:Authorization} ^(.*)
 *      RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]
 */
class AuthorizationHeaderParser
{
    /**
     * parse
     *  Parses the Authorization header into its scheme and credentials.
     *  Returns null when no Authorization header is present.
     */
    public function parse() : null | AuthorizationCredentials
    {
        $authorization = RequestHeader::getHeader('authorization');

        if(!is_string($authorization) || strlen($authorization) == 0)
            return null;

        $authorization = trim($authorization);

        if(!preg_match('/^(bearer|basic)\s+(\S+)\s*$/i', $authorization, $matches))
            throw new UnauthorizedException("authorizationHeaderInvalid", "Authorization header format invalid, expects \"Bearer <token>\" or \"Basic <credentials>\"");

        $scheme = AuthorizationScheme::from(strtolower($matches[1]));

        return new AuthorizationCredentials($scheme, $matches[2]);
    }
}