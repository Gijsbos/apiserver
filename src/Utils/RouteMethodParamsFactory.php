<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use Exception;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;
use ReflectionParameter;
use gijsbos\ApiServer\Classes\OptRequestParam;
use gijsbos\ApiServer\Classes\PathVariable;
use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\ApiServer\Classes\RequestParam;
use gijsbos\ApiServer\Classes\Route;
use gijsbos\ApiServer\Classes\RouteParam;
use InvalidArgumentException;

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
                    return $routeParamClass::createWithoutConstructorFromObject($defaultValue, $paramName, $route, $route->getPathVariables($paramName), $primitiveType);
                else
                    return $routeParamClass::createWithoutConstructor($paramName, $route, $route->getPathVariables($paramName), $primitiveType);

            case RequestParam::class:
            case OptRequestParam::class:
                if($defaultValue instanceof RequestParam || $defaultValue instanceof OptRequestParam)
                    return $routeParamClass::createWithoutConstructorFromObject($defaultValue, $paramName, $route, RequestParam::extractValueFromGlobals($route->getServer()->getRequestMethod(), $paramName, $primitiveType), $primitiveType);
                else
                    return $routeParamClass::createWithoutConstructor($paramName, $route, RequestParam::extractValueFromGlobals($route->getServer()->getRequestMethod(), $paramName, $primitiveType), $primitiveType);

            case RequestHeader::class:
                if($defaultValue instanceof RequestHeader)
                    return $routeParamClass::createWithoutConstructorFromObject($defaultValue, $paramName, $route, RequestHeader::getHeader($paramName), $primitiveType);
                else
                    return $routeParamClass::createWithoutConstructor($paramName, $route, RequestHeader::getHeader($paramName), $primitiveType);

            default:
                throw new RuntimeException("Route Param '$routeParamClass' unknown");
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
                if($routeParam->value == null)
                    $routeParam->value = $routeParam->default;

                // Check if value can be null
                if(!$canBeNull && $routeParam->value == null)
                    $routeParam->value = "";

                // Validate param
                RouteParamValidator::validate($routeParam);

                // Add param to route
                $route->addRouteParam($routeParam);

                // Primitive type set
                if($routeParam->value !== null && $primitiveType !== null)
                {
                    $customType = $routeParam->customType; // Can be set by 'type' property too

                    if(is_string($customType))
                    {
                        $params[$paramName] = match($customType)
                        {
                            "xml" => is_string($routeParam->value) ? simplexml_load_string($routeParam->value) : $routeParam->value,
                            "json" => is_string($routeParam->value) ? json_decode($routeParam->value, true) : $routeParam->value,
                            "base64" => is_string($routeParam->value) ? base64_decode($routeParam->value) : $routeParam->value,
                            default => throw new InvalidArgumentException("Custom type '$customType' invalid"),
                        };
                    }
                    else
                    {
                        $params[$paramName] = match($primitiveType)
                        {
                            "string" => strval($routeParam->value),
                            "int" => intval($routeParam->value),
                            "float" => floatval($routeParam->value),
                            "double" => doubleval($routeParam->value),
                            "bool" => boolval($routeParam->value),
                            "mixed" => $routeParam->value,
                        };
                    }
                }

                // Only RouteParam
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