<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

/**
 * RequestHeader
 */
class RequestHeader extends RouteParam
{
    /**
     * normalizeHeader
     *  Normalize header name (e.g. "X-Custom-Header" → "HTTP_X_CUSTOM_HEADER")
     */
    private static function normalizeHeader(string $headerName)
    {
        return 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
    }

    /**
     * getHeader
     */
    public static function getHeader(string $headerName): ?string
    {
        $key = self::normalizeHeader($headerName);

        // Handle special cases (e.g. Content-Type, Content-Length)
        $specialCases = [
            'CONTENT_TYPE',
            'CONTENT_LENGTH',
            'CONTENT_MD5',
        ];

        // remove "HTTP_" prefix for these
        if (in_array($key, array_map(fn($h) => 'HTTP_' . $h, $specialCases))) {
            $key = substr($key, 5); 
        }

        // Normalize remaining headers
        $headers = array_merge($_SERVER, array_map_assoc(function($k, $v)
        {
            return [self::normalizeHeader($k), $v];
        }, getallheaders()));

        // Return
        return @$headers[$key];
    }
}