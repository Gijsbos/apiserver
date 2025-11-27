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
     * getCustomTypeFilter
     */
    private static function getCustomTypeFilter(?string $customType = null)
    {
        $flags = FILTER_DEFAULT;

        if($customType == null)
            return $flags;

        if($customType == "email")
            $flags = FILTER_VALIDATE_EMAIL;
        else if($customType == "url" || $customType == "uri")
            $flags = FILTER_VALIDATE_URL;
        else if($customType == "mac")
            $flags = FILTER_VALIDATE_MAC;
        else if($customType == "int")
            $flags = FILTER_VALIDATE_INT;
        else if($customType == "float")
            $flags = FILTER_VALIDATE_FLOAT;
        else if($customType == "ip")
            $flags = FILTER_VALIDATE_IP;
        else if($customType == "bool")
            $flags = FILTER_VALIDATE_BOOL;
        else if($customType == "boolean")
            $flags = FILTER_VALIDATE_BOOLEAN;
        else if($customType == "domain")
            $flags = FILTER_VALIDATE_DOMAIN;

        return $flags;
    }

    /**
     * getGetValue
     *  returns false on filter failure
     */
    private static function getGetValue(string $parameterName, ?string $contentType = null, ?string $customType = null)
    {
        $filter = self::getCustomTypeFilter($customType);

        switch($contentType)
        {
            default:
                return is_string($getValue = filter_input(INPUT_GET, $parameterName, $filter)) ? urldecode($getValue) : $getValue;
        }
    }

    /**
     * getPostValue
     */
    private static function getPostValue(string $parameterName, ?string $contentType = null, ?string $customType = null)
    {
        $filter = self::getCustomTypeFilter($customType);

        switch($contentType)
        {
            case "application/json":
                $value = @self::getRequestData($contentType)[$parameterName];
                return $value !== null ? filter_var($value, $filter) : null;

            default:
                return filter_input(INPUT_POST, $parameterName, $filter);
        }
    }

    /**
     * getPutValue
     */
    private static function getPutValue(string $parameterName, ?string $contentType = null, ?string $customType = null)
    {
        $filter = self::getCustomTypeFilter($customType);

        switch($contentType)
        {
            default:
                $value = @self::getRequestData($contentType)[$parameterName];
                return $value !== null ? filter_var($value, $filter) : null;
        }
    }

    /**
     * getPatchValue
     */
    private static function getPatchValue(string $parameterName, ?string $contentType = null, ?string $customType = null)
    {
        $filter = self::getCustomTypeFilter($customType);

        switch($contentType)
        {
            default:
                $value = @self::getRequestData($contentType)[$parameterName];
                return $value !== null ? filter_var($value, $filter) : null;
        }
    }

    /**
     * getDeleteValue
     */
    private static function getDeleteValue(string $parameterName, ?string $contentType = null, ?string $customType = null)
    {
        $filter = self::getCustomTypeFilter($customType);

        switch($contentType)
        {
            default:
                return is_string($getValue = filter_input(INPUT_GET, $parameterName, $filter)) ? urldecode($getValue) : $getValue;
        }
    }

    /**
     * extractValueFromGlobals
     */
    public static function extractValueFromGlobals(string $requestMethod, string $parameterName, ?string $customType = null)
    {
        $contentType = self::getContentType();

        return match($requestMethod)
        {
            "GET" => self::getGetValue($parameterName, $contentType, $customType),
            "POST" => self::getPostValue($parameterName, $contentType, $customType),
            "PUT" => self::getPutValue($parameterName, $contentType, $customType),
            "PATCH" => self::getPatchValue($parameterName, $contentType, $customType),
            "DELETE" => self::getDeleteValue($parameterName, $contentType, $customType),
            default => null,
        };
    }
}