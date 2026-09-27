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
     * mediaType
     *  The media type of a Content-Type header, without parameters and lowercase (RFC 9110 §8.3.1):
     *  "Application/JSON; charset=utf-8" becomes "application/json"
     */
    public static function mediaType(null|string $contentType) : null|string
    {
        if($contentType === null)
            return null;

        $mediaType = strtolower(trim(explode(";", $contentType, 2)[0]));

        return $mediaType !== "" ? $mediaType : null;
    }

    /**
     * isJson
     *  application/json and the structured syntax suffix +json (RFC 6839), e.g. application/merge-patch+json
     */
    public static function isJson(null|string $mediaType) : bool
    {
        return $mediaType === "application/json" || (is_string($mediaType) && str_starts_with($mediaType, "application/") && str_ends_with($mediaType, "+json"));
    }

    /**
     * getContentType
     *  The media type of the request body, see mediaType
     */
    private static function getContentType()
    {
        if(self::$contentType !== null)
            return self::$contentType;

        self::$contentType = self::mediaType(RequestHeader::getHeader("content-type"));

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

        if(self::isJson($contentType))
        {
            if(!is_json($input))
                throw new BadRequestException("jsonInputInvalid", "Payload is not valid json");

            $data = json_decode($input, true);
        }
        else
        {
            parse_str($input, $data); // application/x-www-form-urlencoded, form-like payload
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
        if(self::isJson($contentType))
            return @self::getRequestData($contentType)[$parameterName];

        return filter_input(INPUT_POST, $parameterName);
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
                return is_string($getValue = filter_input(INPUT_GET, $parameterName)) ? urldecode($getValue) : $getValue;
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