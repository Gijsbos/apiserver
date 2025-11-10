<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use ReflectionMethod;
use ReflectionUnionType;
use RuntimeException;
use ReflectionParameter;
use gijsbos\ApiServer\RouteController;
use gijsbos\ApiServer\Classes\PathVariable;
use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\ApiServer\Classes\RequestParam;
use gijsbos\ApiServer\Classes\Route;
use gijsbos\ApiServer\Interfaces\RouteInterface;

/**
 * RouteMethodParamsFactory
 */
class RouteMethodParamsFactory
{
    public function __construct()
    { }

    /**
     * createRouteParam
     */
    private function createRouteParam(ReflectionParameter $parameter, string $routeParamClass, string $name, Route $route, null|string $primitiveType = null)
    {
        $defaultValue = $parameter->getDefaultValue(); // An object has been provided as default parameter, we use it as base because it might contain options

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
    public function generateMethodParams(string $methodName, string $className, RouteInterface $route)
    {
        $params = [];

        $method = new ReflectionMethod($className, $methodName);

        foreach($method->getParameters() as $parameter)
        {
            // Get param name
            $paramName = $parameter->getName();

            // Get type
            $type = $parameter->getType();

            // Multiple types
            if($type instanceof ReflectionUnionType)
            {
                $type = array_map(fn($t) => $t->getName(), $type->getTypes());
            }
            else
            {
                $type = $type->getName();
            }

            // Multiple types
            if(is_array($type))
            {
                $routeParamClasses = array_filter($type, fn($t) => in_array($t, RouteController::CONTROLLER_METHOD_PARAM_TYPES));

                if(count($routeParamClasses) > 1)
                    throw new RuntimeException("Cannot use multiple api method parameter types, got " . implode(", ", $routeParamClasses));
                
                if(count($routeParamClasses) == 1)
                {
                    $routeParamClass = reset($routeParamClasses);

                    $primitiveTypes = array_filter($type, fn($t) => !in_array($t, RouteController::CONTROLLER_METHOD_PARAM_TYPES));

                    $primitiveType = count($primitiveTypes) == 1 ? reset($primitiveTypes) : null;

                    $routeParam = $this->createRouteParam($parameter, $routeParamClass, $paramName, $route, $primitiveType);

                    $route->addRouteParam($routeParam);
                    
                    if(count($primitiveTypes) > 0)
                    {
                        if(count($primitiveTypes) > 1)
                            throw new RuntimeException("Cannot use multiple primitive types, got " . implode(", ", $primitiveTypes));

                        $params[$paramName] = match(reset($primitiveTypes))
                        {
                            "string" => strval($routeParam->value),
                            "int" => intval($routeParam->value),
                            "float" => floatval($routeParam->value),
                            "double" => doubleval($routeParam->value),
                            "bool" => boolval($routeParam->value),
                            "mixed" => $routeParam->value,
                        };
                    }
                    else
                    {
                        $params[$paramName] = $routeParam; // Sets the param as value, requires the user to use ->value
                    }

                    RouteParamValidator::validate($routeParam);
                }
                else
                {
                    $params[$paramName] = null; // Not set
                }
            }
        }

        return $params;
    }
}