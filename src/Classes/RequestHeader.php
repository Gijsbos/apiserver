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
     * normalizeHeaders
     */
    private static function normalizeHeaders(array $headers)
    {
        return array_map_assoc(function($k, $v) {
            return [self::normalizeHeader($k), $v];
        }, $headers);
    }

    /**
     * getAllHeaders
     */
    private static function getAllHeaders(?string $headerName = null)
    {
        $headers = self::normalizeHeaders(@getallheaders() ?? []);

        if(is_string($headerName))
        {
            return @$headers[$headerName];
        }

        return $headers;
    }

    /**
     * getHeader
     */
    public static function getHeader(string $headerName, bool $includeServerHeaders = false): ?string
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
        if($includeServerHeaders)
        {
            $serverHeaders = self::normalizeHeaders($_SERVER);

            return @$serverHeaders[$key] ?? self::getAllHeaders($key); // Looks in server headers, then the get all headers
        }
        else
        {
            return self::getAllHeaders($key);
        }
    }
}