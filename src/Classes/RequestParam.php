<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

use gijsbos\Http\Exceptions\BadRequestException;

/**
 * RequestParam
 */
class RequestParam extends RouteParam
{
    public static $requestData = null;

    /**
     * getRequestData
     */
    private static function getRequestData()
    {
        if(self::$requestData !== null) // Prevent parsing for every parameter
            return self::$requestData;

        $input = file_get_contents('php://input');

        $contentType = RequestHeader::getHeader("content-type");

        switch($contentType)
        {
            case "application/json":
                if(!is_json($input))
                    throw new BadRequestException("jsonInputInvalid", "Payload is not valid json");

                $data = json_decode($input, true);

            case "application/x-www-form-urlencoded":
                parse_str($input, $data); // form-like payload
        }

        self::$requestData = $data;

        return $data;
    }

    /**
     * getRequestParamValue
     */
    private static function getRequestParamValue(string $parameterName)
    {
        $requestData = self::getRequestData();

        if(!is_array($requestData))
            throw new BadRequestException("requestBodyInvalid", "Request body does not contain valid data");

        return $requestData[$parameterName];
    }

    public static function extractValueFromGlobals(string $requestMethod, string $parameterName, null|string $type = null)
    {
        return match($requestMethod)
        {
            "GET" => filter_input(INPUT_GET, $parameterName),
            "POST" => filter_input(INPUT_POST, $parameterName),
            "PUT" => self::getRequestParamValue($parameterName),
            "PATCH" => self::getRequestParamValue($parameterName),
            "DELETE" => self::getRequestParamValue($parameterName),
            default => null,
        };
    }
}