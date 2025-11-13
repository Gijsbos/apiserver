<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

use gijsbos\ApiServer\Interfaces\RouteParamInterface;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionProperty;

/**
 * RouteParam
 */
class RouteParam implements RouteParamInterface
{
    const WORD_PATTERN = "/^\w+$/";
    const URI_PATTERN = "/^https?:\/\//";

    public string $name = "";
    public null|Route $route = null;
    public mixed $value = null;
    public null|string $type = null;
    public mixed $min = null;
    public mixed $max = null;
    public null|string $pattern = null;
    public null|array $values = null;
    public bool $required = false;
    public mixed $default = null;
    public array $opts = [];

    public function __construct(array $opts = [])
    {
        $this->min = @$opts["min"];
        $this->max = @$opts["max"];
        $this->pattern = $this->initPattern(@$opts["pattern"]);
        $this->values = @$opts["values"];
        $this->required = @$opts["required"] ?? true;
        $this->default = @$opts["default"];
    }

    /**
     * extractPatternAttributeFromProperty
     */
    private function extractPatternAttributeFromProperty(string $className, string $propertyName)
    {
        $property = new ReflectionProperty($className, $propertyName);

        $patterns = array_values(array_filter($property->getAttributes(), fn($a) => $a->getName() == Pattern::class));

        if(count($patterns) == 0)
            throw new LogicException("Property '{$propertyName}' does not define the 'Pattern' attribute in class '$className'");

        $pattern = reset($patterns);

        return $pattern->newInstance()->getRegExp();
    }

    /**
     * initPattern
     */
    private function initPattern($pattern)
    {
        if($pattern == null)
            return null;

        if(is_string($pattern))
            return $pattern;

        else if(is_array($pattern))
        {
            if(count($pattern) == 1)
            {
                [$className] = $pattern;

                if(!class_exists($className))
                    throw new LogicException("Pattern array argument using '$className' does not exist");

                if(!property_exists($className, $this->name))
                    throw new LogicException("Pattern array argument using '$className::{$this->name}' does not exist");

                return $this->extractPatternAttributeFromProperty($className, $this->name);
            }
            else if(count($pattern) == 2)
            {
                [$className, $propertyOrMethodName] = $pattern;

                if(!is_string($className) || !is_string($propertyOrMethodName))
                    throw new InvalidArgumentException("Pattern array argument invalid, expected [className,propertyOrMethodName]");

                if(property_exists($className, $propertyOrMethodName))
                {
                    return $this->extractPatternAttributeFromProperty($className, $propertyOrMethodName);
                }
                else if(method_exists($className, $propertyOrMethodName))
                {
                    if(!is_callable([$className, $propertyOrMethodName]))
                        throw new InvalidArgumentException("Pattern array argument is not callable using [$className, $propertyOrMethodName]");

                    $pattern = $className::$propertyOrMethodName();

                    if(!is_string($pattern))
                        throw new InvalidArgumentException("Pattern array argument using callable [$className, $propertyOrMethodName] returned an invalid type '".gettype($pattern)."', expected string");

                    return $pattern;
                }
                else
                    throw new InvalidArgumentException("Pattern argument invalid using [$className,$propertyOrMethodName]', expected valid [className,propertyOrMethodName]");
            }
        }

        throw new InvalidArgumentException("Pattern argument type '".gettype($pattern)."', expected string|[className,propertyOrMethodName]");
    }

    public function getName()
    {
        return $this->name;
    }

    public function getRoute()
    {
        return $this->route;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getMin()
    {
        return $this->min;
    }

    public function getMax()
    {
        return $this->max;
    }

    public function getPattern()
    {
        return $this->pattern;
    }

    public function getValues()
    {
        return $this->values;
    }

    public function isRequired()
    {
        return $this->required;
    }

    public function getDefault()
    {
        return $this->default;
    }

    public function getOpts()
    {
        return $this->value;
    }

    public static function createWithoutConstructorFromObject($object, string $name, Route $route, mixed $value = null, mixed $type = null)
    {
        $object->name = $name;
        $object->route = $route;
        $object->value = $value;
        $object->type = $type;
        return $object;
    }

    public static function createWithoutConstructor(string $name, Route $route, mixed $value = null, mixed $type = null)
    {
        return self::createWithoutConstructorFromObject((new ReflectionClass(__CLASS__))->newInstanceWithoutConstructor(), $name, $route, $value, $type);
    }
}