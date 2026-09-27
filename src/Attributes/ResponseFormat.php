<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * ResponseFormat
 *  Answers a route in one format, whatever the Accept header asks: results and errors alike.
 *  For documents a standard defines as JSON, e.g. an OpenID Connect discovery document or a JWK Set:
 *
 *      #[GetRoute("/keys")]
 *      #[ResponseFormat("json")]
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ResponseFormat extends RouteAttribute
{
    const FORMATS = ["json", "xml"];

    public function __construct(public readonly string $format)
    {
        if(!in_array($format, self::FORMATS, true))
            throw new InvalidArgumentException("ResponseFormat '$format' is not supported, expected " . implode(" or ", self::FORMATS));
    }
}
