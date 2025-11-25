<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

use gijsbos\Http\Exceptions\BadRequestException;

/**
 * RequestParam
 */
class RequestParam extends RouteParam
{
    public static $contentType = null;
    public static $requestData = null;

    /**
     * getContentType
     */
    private static function getContentType()
    {
        if(self::$contentType !== null)
            return self::$contentType;

        self::$contentType = RequestHeader::getHeader("content-type");

        return self::$contentType;
    }

    /**
     * getRequestData
     */
    private static function getRequestData(?string $contentType = null)
    {
        if(self::$requestData !== null) // Prevent parsing for every parameter
            return self::$requestData;

        $input = file_get_contents('php://input');

        switch($contentType)
        {
            case "application/json":
                if(!is_json($input))
                    throw new BadRequestException("jsonInputInvalid", "Payload is not valid json");

                $data = json_decode($input, true);
            break;

            case "application/x-www-form-urlencoded":
            default:
                parse_str($input, $data); // form-like payload
        }

        self::$requestData = $data;

        return self::$requestData;
    }

    /**
     * getGetValue
     */
    private static function getGetValue(string $parameterName, ?string $contentType = null)
    {
        switch($contentType)
        {
            default:
                return is_string($getValue = filter_input(INPUT_GET, $parameterName)) ? urldecode($getValue) : $getValue;
        }
    }

    /**
     * getPostValue
     */
    private static function getPostValue(string $parameterName, ?string $contentType = null)
    {
        switch($contentType)
        {
            case "application/json":
                return @self::getRequestData($contentType)[$parameterName];

            default:
                return filter_input(INPUT_POST, $parameterName);
        }
    }

    /**
     * getPutValue
     */
    private static function getPutValue(string $parameterName, ?string $contentType = null)
    {
        switch($contentType)
        {
            default:
                return @self::getRequestData($contentType)[$parameterName];
        }
    }

    /**
     * getPatchValue
     */
    private static function getPatchValue(string $parameterName, ?string $contentType = null)
    {
        switch($contentType)
        {
            default:
                return @self::getRequestData($contentType)[$parameterName];
        }
    }

    /**
     * getDeleteValue
     */
    private static function getDeleteValue(string $parameterName, ?string $contentType = null)
    {
        switch($contentType)
        {
            default:
                return is_string($deleteValue = filter_input(INPUT_GET, $parameterName)) ? urldecode($deleteValue) : $deleteValue; // Fetches from query (most conventional rather than payload)
        }
    }

    /**
     * extractValueFromGlobals
     */
    public static function extractValueFromGlobals(string $requestMethod, string $parameterName)
    {
        $contentType = self::getContentType();

        return match($requestMethod)
        {
            "GET" => self::getGetValue($parameterName, $contentType),
            "POST" => self::getPostValue($parameterName, $contentType),
            "PUT" => self::getPutValue($parameterName, $contentType),
            "PATCH" => self::getPatchValue($parameterName, $contentType),
            "DELETE" => self::getDeleteValue($parameterName, $contentType),
            default => null,
        };
    }
}