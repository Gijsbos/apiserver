<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;
use ReflectionParameter;
use gijsbos\ApiServer\Attributes\Route;
use gijsbos\ApiServer\Classes\OptRequestParam;
use gijsbos\ApiServer\Classes\PathVariable;
use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\ApiServer\Classes\RequestParam;
use gijsbos\ApiServer\Classes\RouteParam;
use gijsbos\Http\Exceptions\BadRequestException;

/**
 * RouteMethodParamsFactory
 */
class RouteMethodParamsFactory
{
    public function __construct()
    { }

    /**
     * getDataFromReflectionNamedType
     */
    private function getDataFromReflectionNamedType(ReflectionNamedType $type, null|string &$primitiveType = null)
    {
        $typeName = $type->getName();

        if(class_exists($typeName))
        {
            if(is_subclass_of($typeName, RouteParam::class))
            {
                return $type->getName();
            }
        }
        else
        {
            $primitiveType = $type->getName();
        }

        return null;
    }
    /**
     * getRouteParamClassFromTypes
     */
    private function getRouteParamClassFromParameter(ReflectionParameter $parameter, null|string &$primitiveType = null, null|bool &$canBeNull = null)
    {
        $type = $parameter->getType();

        if($type instanceof ReflectionUnionType)
        {
            $routeParamClass = null;
            $canBeNull = false;

            foreach($type->getTypes() as $type)
            {
                $rpc = $this->getDataFromReflectionNamedType($type, $pt);

                if($rpc !== null)
                    $routeParamClass = $rpc;

                if($pt === 'null')
                    $canBeNull = true;

                else if($pt !== null)
                    $primitiveType = $pt;
            }

            return $routeParamClass;
        }
        else
        {
            return $this->getDataFromReflectionNamedType($type, $primitiveType);
        }
    }

    /**
     * createRouteParam
     */
    private function createRouteParam(ReflectionParameter $parameter, string $routeParamClass, Route $route, null|string $primitiveType = null)
    {
        $paramName = $parameter->getName();

        $defaultValue =  $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null; // An object has been provided as default parameter, we use it as base because it might contain options

        switch($routeParamClass)
        {
            case PathVariable::class:
                if($defaultValue instanceof PathVariable)
                {
                    $customType = $defaultValue->customType ?? $defaultValue->type;

                    return $routeParamClass::createWithoutConstructorFromObject($defaultValue, $paramName, $route, $route->getPathVariables($paramName), $primitiveType, $customType);
                }
                else
                    return $routeParamClass::createWithoutConstructor($paramName, $route, $route->getPathVariables($paramName), $primitiveType);

            case RequestParam::class:
            case OptRequestParam::class:
                if($defaultValue instanceof RequestParam || $defaultValue instanceof OptRequestParam)
                {
                    $customType = $defaultValue->customType ?? $defaultValue->type;

                    return $routeParamClass::createWithoutConstructorFromObject($defaultValue, $paramName, $route, RequestParam::extractValueFromGlobals($route->getServer()->getRequestMethod(), $paramName), $primitiveType, $customType);
                }
                else
                    return $routeParamClass::createWithoutConstructor($paramName, $route, RequestParam::extractValueFromGlobals($route->getServer()->getRequestMethod(), $paramName), $primitiveType);

            case RequestHeader::class:
                if($defaultValue instanceof RequestHeader)
                {
                    $customType = $defaultValue->customType ?? $defaultValue->type;

                    return $routeParamClass::createWithoutConstructorFromObject($defaultValue, $paramName, $route, RequestHeader::getHeader($paramName), $primitiveType, $customType);
                }
                else
                    return $routeParamClass::createWithoutConstructor($paramName, $route, RequestHeader::getHeader($paramName), $primitiveType);

            default:
                throw new RuntimeException("Route Param '$routeParamClass' unknown");
        }
    }

    /**
     * parseCustomTypeValues
     */
    private function parseCustomTypeValues(RouteParam $routeParam)
    {
        $paramName = $routeParam->name;

        switch($routeParam->customType)
        {
            case "xml":
                // null is allowed as default value
                if(!is_null($routeParam->value) && !is_string($routeParam->value))
                    throw new BadRequestException("xmlInputInvalid", "Parameter '$paramName' expects valid xml");
                
                if(is_string($routeParam->value))
                {
                    libxml_use_internal_errors(true);

                    $xml = simplexml_load_string($routeParam->value);

                    if($xml === false)
                        throw new BadRequestException("xmlInputInvalid", "Parameter '$paramName' expects valid xml");

                    $routeParam->value = $xml;
                }
                
            break;

            case "json":
                // null and array are allowed as default values
                if(!is_null($routeParam->value) && !is_array($routeParam->value) && (!is_string($routeParam->value) || !is_json($routeParam->value)))
                    throw new BadRequestException("jsonInputInvalid", "Parameter '$paramName' expects valid json");

                $routeParam->value = is_string($routeParam->value) ? json_decode($routeParam->value, true) : $routeParam->value;
            break;

            case "base64":
                // null is allowed as default value
                if(!is_null($routeParam->value) && !is_string($routeParam->value))
                    throw new BadRequestException("base64InputInvalid", "Parameter '$paramName' expects valid base64");

                $routeParam->value = is_string($routeParam->value) ? base64_decode($routeParam->value) : $routeParam->value;
            break;
        }
    }

    /**
     * generateMethodParams
     */
    public function generateMethodParams(Route $route)
    {
        $params = [];
 
        foreach($route->getReflectionClassMethod()->getParameters() as $parameter)
        {
            $paramName = $parameter->getName();

            // Get route param className
            $routeParamClassName = $this->getRouteParamClassFromParameter($parameter, $primitiveType, $canBeNull);

            // Found Route Param
            if($routeParamClassName !== null)
            {
                // Create instance with value
                $routeParam = $this->createRouteParam($parameter, $routeParamClassName, $route, $primitiveType);

                // Set default value
                if($routeParam->value === null)
                    $routeParam->value = $routeParam->default;

                // Check if value can be null
                if(!$canBeNull && $routeParam->value === null)
                    $routeParam->value = "";

                // Validate param
                RouteParamValidator::validate($routeParam);

                // Add param to route
                $route->addRouteParam($routeParam);

                // Check custom types, can be set by both 'type' and 'customType' in RouteParam constructor
                $customType = $routeParam->customType;

                // Primitive type set
                if($routeParam->value !== null && ($primitiveType !== null || $customType !== null))
                {
                    $routeParam->value = match($primitiveType)
                    {
                        "string" => strval($routeParam->value),
                        "int" => is_string($routeParam->value) && strlen($routeParam->value) == 0 ? null : intval($routeParam->value),
                        "float" => is_string($routeParam->value) && strlen($routeParam->value) == 0 ? null : floatval($routeParam->value),
                        "double" => is_string($routeParam->value) && strlen($routeParam->value) == 0 ? null : doubleval($routeParam->value),
                        "bool" => is_string($routeParam->value) && strlen($routeParam->value) == 0 ? null : boolval($routeParam->value),
                        "mixed" => $routeParam->value,
                        default => $routeParam->value
                    };

                    if(is_string($customType))
                    {
                        $this->parseCustomTypeValues($routeParam);
                    }
                    
                    $params[$paramName] = $routeParam->value;
                }

                else
                {
                    $params[$paramName] = $routeParam->value; // Set value
                }
            }
            else
            {
                $params[$paramName] = null;
            }
        }

        return $params;
    }
}