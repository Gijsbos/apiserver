<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use ReflectionMethod;
use ReflectionUnionType;
use RuntimeException;
use gijsbos\ApiServer\RouteController;
use gijsbos\ApiServer\Interfaces\RouteInterface;

/**
 * RouteMethodParamsFactory
 */
class RouteMethodParamsFactory
{
    public function __construct()
    { }

    /**
     * generateMethodParams
     */
    public function generateMethodParams(string $methodName, string $className, RouteInterface $route)
    {
        $params = [];

        $method = new ReflectionMethod($className, $methodName);

        foreach($method->getParameters() as $parameter)
        {
            $paramName = $parameter->getName();

            $type = $parameter->getType();

            if($type instanceof ReflectionUnionType)
            {
                $type = array_map(fn($t) => $t->getName(), $type->getTypes());
            }
            else
            {
                $type = $type->getName();
            }

            if(is_array($type))
            {
                $apiParamTypes = array_filter($type, fn($t) => in_array($t, RouteController::CONTROLLER_METHOD_PARAM_TYPES));

                if(count($apiParamTypes) > 1)
                    throw new RuntimeException("Cannot use multiple api method parameter types, got " . implode(", ", $apiParamTypes));
                
                if(count($apiParamTypes) == 1)
                {
                    $apiParamType = reset($apiParamTypes);

                    $apiParam = new $apiParamType($paramName, $route);

                    $nonApiParamTypes = array_filter($type, fn($t) => !in_array($t, RouteController::CONTROLLER_METHOD_PARAM_TYPES));
                    
                    if(count($nonApiParamTypes) > 0)
                    {
                        if(count($nonApiParamTypes) > 1)
                            throw new RuntimeException("Cannot use multiple primitive types, got " . implode(", ", $nonApiParamTypes));

                        $params[$paramName] = match(reset($nonApiParamTypes))
                        {
                            "string" => strval($apiParam->value),
                            "int" => intval($apiParam->value),
                            "float" => floatval($apiParam->value),
                            "double" => doubleval($apiParam->value),
                            "bool" => boolval($apiParam->value),
                            "mixed" => $apiParam->value,
                        };
                    }
                    else
                    {
                        $params[$paramName] = $apiParam; // Sets the param as value, requires the user to use ->value
                    }
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