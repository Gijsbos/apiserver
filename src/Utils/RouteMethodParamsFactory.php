<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;
use ReflectionParameter;
use gijsbos\ApiServer\Classes\PathVariable;
use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\ApiServer\Classes\RequestParam;
use gijsbos\ApiServer\Classes\Route;
use gijsbos\ApiServer\Classes\RouteParam;

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
    private function getRouteParamClassFromParameter(ReflectionParameter $parameter, null|string &$primitiveType = null)
    {
        $type = $parameter->getType();

        if($type instanceof ReflectionUnionType)
        {
            $routeParamClass = null;

            foreach($type->getTypes() as $type)
            {
                $rpc = $this->getDataFromReflectionNamedType($type, $pt);

                if($rpc !== null)
                    $routeParamClass = $rpc;

                if($pt !== null)
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
    private function createRouteParam(ReflectionParameter $parameter, string $routeParamClass, string $name, Route $route, null|string $primitiveType = null)
    {
        $defaultValue =  $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null; // An object has been provided as default parameter, we use it as base because it might contain options

        switch($routeParamClass)
        {
            case PathVariable::class:

                if($defaultValue instanceof PathVariable)
                    return $routeParamClass::createWithoutConstructorFromObject($defaultValue, $name, $route, $route->getPathVariables($name), $primitiveType);
                else
                    return $routeParamClass::createWithoutConstructor($name, $route, $route->getPathVariables($name), $primitiveType);

            case RequestParam::class:
                if($defaultValue instanceof RequestParam)
                    return $routeParamClass::createWithoutConstructorFromObject($defaultValue, $name, $route, RequestParam::extractValueFromGlobals($route->getServer()->getRequestMethod(), $name, $primitiveType), $primitiveType);
                else
                    return $routeParamClass::createWithoutConstructor($name, $route, RequestParam::extractValueFromGlobals($route->getServer()->getRequestMethod(), $name, $primitiveType), $primitiveType);

            case RequestHeader::class:
                if($defaultValue instanceof RequestHeader)
                    return $routeParamClass::createWithoutConstructorFromObject($defaultValue, $name, $route, RequestHeader::getHeader($name), $primitiveType);
                else
                    return $routeParamClass::createWithoutConstructor($name, $route, RequestHeader::getHeader($name), $primitiveType);

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
            $routeParamClassName = $this->getRouteParamClassFromParameter($parameter, $primitiveType);

            // Found Route Param
            if($routeParamClassName !== null)
            {
                // Create instance with value
                $routeParam = $this->createRouteParam($parameter, $routeParamClassName, $paramName, $route, $primitiveType);

                // Set default value
                if($routeParam->value == null)
                    $routeParam->value = $routeParam->default;

                // Validate param
                RouteParamValidator::validate($routeParam);

                // Add param to route
                $route->addRouteParam($routeParam);
                
                // Primitive type set
                if($primitiveType !== null)
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