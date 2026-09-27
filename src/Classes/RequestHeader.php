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
        $headerName = strtoupper(str_replace('-', '_', $headerName));
        return str_starts_with($headerName, "HTTP_") ? $headerName : "HTTP_" . $headerName;
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

        // Remove "HTTP_" prefix for these
        if (in_array($key, array_map(fn($h) => 'HTTP_' . $h, $specialCases))) {
            $key = substr($key, 5); 
        }

        return $_SERVER[$key] ?? $_SERVER["REDIRECT_$key"] ?? self::getFromAllHeaders($headerName);
    }

    /**
     * getFromAllHeaders
     *  Fallback for servers that do not put every header in $_SERVER, getallheaders() uses the header names
     *  as sent ("X-Custom-Header"), matched case insensitive
     */
    private static function getFromAllHeaders(string $headerName) : null|string
    {
        if(!function_exists('getallheaders'))
            return null;

        $headerName = strtolower(str_replace('_', '-', preg_replace('/^HTTP_/i', '', $headerName)));

        foreach((array) \getallheaders() as $name => $value)
            if(strtolower((string) $name) === $headerName)
                return (string) $value;

        return null;
    }
}