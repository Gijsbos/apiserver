<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use ReflectionAttribute;
use ReflectionMethod;
use ReflectionUnionType;
use ReflectionNamedType;
use LogicException;
use InvalidArgumentException;

use gijsbos\ApiServer\Attributes\DocsProperty;
use gijsbos\ApiServer\Attributes\ReturnFilter;
use gijsbos\ApiServer\Attributes\Route;
use gijsbos\ApiServer\Classes\OptRequestParam;
use gijsbos\ApiServer\Classes\PathVariable;
use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\ApiServer\Classes\RouteParam;

/**
 * DocsFactory
 */
class DocsFactory
{
    public function __construct()
    { }

    private function extractDocs($methodOrClass)
    {
        $result = [];

        foreach($methodOrClass->getAttributes(DocsProperty::class, ReflectionAttribute::IS_INSTANCEOF) as $docsProperty)
        {
            $docsData = $docsProperty->newInstance()->export();

            if(count($docsData) > 0)
            {
                $result = array_merge($result, $docsData);
            }
        }

        return $result;
    }

    private function parseParam(ReflectionNamedType $reflectionType, &$parameterData)
    {
        $name = $reflectionType->getName();
                    
        if(class_exists($name))
        {
            $parameterData["kind"] = $name == PathVariable::class ? "pathVariable" : ($name == RequestHeader::class ? "requestHeader" : "requestParam");

            if($name == OptRequestParam::class)
                $parameterData["required"] = false;
        }
        else
        {
            if(!is_string(@$parameterData["type"]))
                $parameterData["type"] = $name;
        }
    }
    
    private function extractParameters(ReflectionMethod $method)
    {
        $className = $method->getDeclaringClass()->getName();
        $methodName = $method->getName();

        $parameters = [];

        foreach($method->getParameters() as $parameter)
        {
            $parameterName = $parameter->getName();

            $parameterData = [
                "name" => $parameterName,
            ];

            $type = $parameter->getType();

            if($type instanceof ReflectionUnionType)
            {
                $parameterData["canBeNull"] = $type->allowsNull();

                $unionTypes = $type->getTypes();

                foreach($unionTypes as $unionType)
                {
                    $this->parseParam($unionType, $parameterData);
                }
            }
            else if(is_subclass_of($type->getName(), RouteParam::class))
            {
                $this->parseParam($type, $parameterData);
            }

            $defaultValue =  $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;

            if($defaultValue !== null)
            {
                $defaultData = array_filter((array) $defaultValue, function($v)
                {
                    if($v == null)
                        return false;

                    if(is_string($v) && strlen($v) == 0)
                        return false;

                    return true;
                });
                
                $parameterData = array_merge($parameterData, $defaultData);
            }

            if(!is_null($pattern = @$parameterData["pattern"]))
            {
                $patternParameterName = $parameterName;
                
                if(is_array($pattern))
                {
                    if(count($pattern) !== 2)
                        throw new InvalidArgumentException("Invalid route param regexp pattern for param '{$parameterName}'");

                    [$pattern, $patternParameterName] = $pattern;
                }

                if(class_exists($pattern))
                {
                    if(property_exists($pattern, $patternParameterName))
                    {
                        $result = RouteParamValidator::extractPatternAttributeFromProperty($pattern, $patternParameterName, false);

                        if($result === false)
                            throw new LogicException("Route param '{$parameterName}' in '{$className}::{$methodName}' does not define a regexp pattern in class '$pattern::$patternParameterName'");

                        $parameterData["pattern"] = $result;
                    }
                }
            }

            $parameters[] = $parameterData;
        }

        return $parameters;
    }

    private function parseMethod(ReflectionMethod $method)
    {
        $route = $method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF)[0]->newInstance();

        $routeData = [
            "method" => $method->getName(),
            "requestMethod" => $route->getRequestMethod(),
            "statusCode" => $route->getStatusCode(),
            "path" => $route->getPath(),
            "pathVariableNames" => $route->getPathVariableNames(),
            "parameters" => $this->extractParameters($method),
        ];

        $returnFilters = $method->getAttributes(ReturnFilter::class, ReflectionAttribute::IS_INSTANCEOF);

        if(count($returnFilters))
        {
            $routeData["returnFilter"] = $returnFilters[0]->newInstance()->getFilter();
        }

        $routeData = array_merge($routeData, $this->extractDocs($method));

        return $routeData;
    }

    public function create(null|callable $routeDataCallback = null)
    {
        $docs = [
            "controllers" => []
        ];

        foreach(RouteParser::getRouteControllerClasses() as $class)
        {
            $controllerData = [
                "class" => $class->getName(),
                "routes" => [],
            ];

            $controllerData = array_merge($controllerData, $this->extractDocs($class));

            foreach(RouteParser::getRouteControllerClassMethods($class) as $method)
            {
                $routeData = $this->parseMethod($method);

                if(count($routeData))
                {
                    if($routeDataCallback !== null)
                    {
                        $result = $routeDataCallback($routeData, $method, $class);

                        if($result === false)
                            continue;

                        if(is_array($result)) // Return is used, we assume its value
                            $routeData = $result;
                    }

                    $controllerData["routes"][] = $routeData;
                }
            }

            if(count($controllerData["routes"]) > 0)
                $docs["controllers"][] = $controllerData;
        }

        return $docs;
    }
}