<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

/**
 * RequestHeader
 */
class RequestHeader extends RouteParam
{
    /**
     * getHeader
     */
    public static function getHeader(string $headerName): ?string
    {
        // Normalize header name (e.g. "X-Custom-Header" → "HTTP_X_CUSTOM_HEADER")
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));

        // Handle special cases (e.g. Content-Type, Content-Length)
        $specialCases = [
            'CONTENT_TYPE',
            'CONTENT_LENGTH',
            'CONTENT_MD5',
        ];

        if (in_array($key, array_map(fn($h) => 'HTTP_' . $h, $specialCases))) {
            $key = substr($key, 5); // remove "HTTP_" prefix for these
        }

        return $_SERVER[$key] ?? null;
    }
}