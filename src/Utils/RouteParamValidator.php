<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use LogicException;
use InvalidArgumentException;
use ReflectionProperty;
use gijsbos\ExtFuncs\Attributes\RegExp;
use gijsbos\ApiServer\Classes\RouteParam;
use gijsbos\Http\Exceptions\BadRequestException;

/**
 * RouteParamValidator
 */
abstract class RouteParamValidator
{
    /**
     * getTypeFromValue
     */
    public static function getTypeFromValue($value)
    {
        if(is_string($value))
        {
            if(is_numeric($value))
            {
                if(strpos($value, ".") !== false)
                {
                    return "double";
                }
                else
                {
                    return "int";
                }
            }
        }

        return "string";
    }

    /**
     * extractPatternAttributeFromProperty
     */
    public static function extractPatternAttributeFromProperty(string $className, string $propertyName)
    {
        $property = new ReflectionProperty($className, $propertyName);

        $patterns = array_values(array_filter($property->getAttributes(), fn($a) => $a->getName() == RegExp::class || is_subclass_of($a->getName(), RegExp::class)));

        if(count($patterns) == 0)
            throw new LogicException("Property '{$propertyName}' does not define the 'Pattern' attribute in class '$className'");

        $pattern = reset($patterns);

        return $pattern->newInstance()->getRegExp();
    }

    /**
     * parsePatternInput
     *  Parses pattern input
     */
    public static function parsePatternInput(RouteParam $p) : null | string
    {
        $pattern = $p->pattern;
        $propertyName = $p->name;

        if($pattern == null)
            return null;

        if(is_string($pattern))
        {
            if(class_exists($pattern))
                return self::extractPatternAttributeFromProperty($pattern, $propertyName);
            else
                return $pattern;
        }

        else if(is_array($pattern))
        {
            if(count($pattern) == 1)
            {
                [$className] = $pattern;

                if(!class_exists($className))
                    throw new LogicException("Pattern array argument using '$className' does not exist");

                if(!property_exists($className, $propertyName))
                    throw new LogicException("Pattern array argument using '$className::{$propertyName}' does not exist");

                return self::extractPatternAttributeFromProperty($className, $propertyName);
            }
            else if(count($pattern) == 2)
            {
                [$className, $propertyOrMethodName] = $pattern;

                if(!is_string($className) || !is_string($propertyOrMethodName))
                    throw new InvalidArgumentException("Pattern array argument invalid, expected [className,propertyOrMethodName]");

                if(property_exists($className, $propertyOrMethodName))
                {
                    return self::extractPatternAttributeFromProperty($className, $propertyOrMethodName);
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

    /**
     * validate
     */
    public static function validate(RouteParam $p)
    {
        if($p->value == false && is_string($p->customType))
        {
            throw new BadRequestException($p->name."TypeInvalid", "Parameter '{$p->name}' is not of type '{$p->customType}'");
        }

        if($p->value === null || $p->value === "")
        {
            if($p->isRequired())
                throw new BadRequestException($p->name."InputInvalid", "Parameter '{$p->name}' is required");
            else
                return true;
        }

        if(is_array($p->values))
        {
            if(!in_array($p->value, $p->values))
                throw new BadRequestException($p->name."ValueInvalid", "Parameter '{$p->name}' value does not match " . implode("|", $p->values));
        }

        $type = is_string($p->type) ? $p->type : self::getTypeFromValue($p->value);

        switch($type)
        {
            case "int":
            case "float":
            case "double":
                if(!is_numeric($p->value))
                    throw new BadRequestException($p->name."Invalid", "Parameter '{$p->name}' must be of type '$type'", ["details" => ["received" => $p->value, "expected" => "$type"]]);

                if(isset($p->min) && $p->value < $p->min)
                    throw new BadRequestException($p->name."ValueMinExceeded", "Parameter '{$p->name}' cannot be less than '{$p->min}'");

                if(isset($p->max) && $p->value > $p->max)
                    throw new BadRequestException($p->name."ValueMaxExceeded", "Parameter '{$p->name}' cannot be greater than '{$p->max}'");
                break;

            case "bool":
                if($p->value !== false && $p->value !== true && $p->value !== 0 && $p->value !== 1 && $p->value !== "0" && $p->value !== "1")
                    throw new BadRequestException($p->name."Invalid", "Parameter '{$p->name}' must be of type '$type'", ["details" => ["received" => $p->value, "expected" => "$type"]]);
                break;

            case "string":

                $p->pattern = self::parsePatternInput($p);

                if(!is_string($p->value))
                    throw new LogicException("String parameter validator received type '".gettype($p->value)."' for argument '{$p->name}'");

                if(isset($p->min) && mb_strlen($p->value) < $p->min)
                    throw new BadRequestException($p->name."LengthMinExceeded", "Parameter '{$p->name}' cannot be less than '{$p->min}'");

                if(isset($p->max) && mb_strlen($p->value) > $p->max)
                    throw new BadRequestException($p->name."LengthMaxExceeded", "Parameter '{$p->name}' cannot be greater than '{$p->max}'");

                if(isset($p->pattern) && preg_match($p->pattern, $p->value) == 0)
                    throw new BadRequestException($p->name."ValuePatternFailure", "Parameter '{$p->name}' does not match '{$p->pattern}'");
                
                break;
        }
    }
}