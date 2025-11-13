<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use LogicException;
use ReflectionProperty;
use gijsbos\ApiServer\Classes\Pattern;
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
     * extractPatternFromProperty
     */
    private static function extractPatternFromProperty(string $className, string $propertyName)
    {
        $property = new ReflectionProperty($className, $propertyName);

        $patterns = array_values(array_filter($property->getAttributes(), fn($a) => $a->getName() == Pattern::class));

        if(count($patterns) == 0)
            throw new LogicException("Property '{$propertyName}' does not define the Pattern attribute in class '$className'");

        $pattern = reset($patterns);

        return $pattern->newInstance()->getRegExp();
    }

    /**
     * extractPattern
     */
    private static function extractPattern(RouteParam $p, string $className)
    {
        // Not set, or Empty String / String is Not A class (but a usual pattern), Or Array
        if($p->pattern == null || (is_string($p->pattern) && (strlen($p->pattern) == 0 || !class_exists($p->pattern))) || !is_array($p->pattern))
            return $p->pattern;

        // If pattern is array with single value
        if(is_array($p->pattern) && count($p->pattern) == 1)
            $p->pattern = reset($p->pattern);

        // Not class
        if(!class_exists($p->pattern))
            throw new LogicException("Property '{$p->name}' incorrectly defined pattern as array, expected [className, ?classProperty]");

        // Property does not exist
        if(is_string($p->pattern))
        {
            if(!property_exists($className, $p->name))
                throw new LogicException("Property '{$p->name}' does not exist in class '$className'");

            return self::extractPatternFromProperty($className, $p->name);
        }
        else if(is_array($p->pattern))
        {
            if(count($p->pattern) == 2)
            {
                [$className, $propertyName] = $p->pattern;

                if(!class_exists($className))
                    throw new LogicException("Property '{$p->name}' pattern class '$className' does not exist");

                if(!property_exists($className, $propertyName))
                    throw new LogicException("Property '{$p->name}' pattern class property '$className::$propertyName' does not exist");

                return self::extractPatternFromProperty($className, $propertyName);
            }
        }

        throw new LogicException("Pattern value type '".gettype($p->pattern)."' invalid, expected string|array");
    }

    /**
     * validate
     */
    public static function validate(RouteParam $p)
    {
        if($p->value == null)
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
                if($p->value !== "0" && $p->value !== "1")
                    throw new BadRequestException($p->name."Invalid", "Parameter '{$p->name}' must be of type '$type'", ["details" => ["received" => $p->value, "expected" => "$type"]]);
                break;

            case "string":
                if(!is_string($p->value))
                    throw new LogicException("String parameter validator received type '".gettype($p->value)."' for argument '{$p->name}'");

                if(isset($p->min) && mb_strlen($p->value) < $p->min)
                    throw new BadRequestException($p->name."LengthMinExceeded", "Parameter '{$p->name}' cannot be less than '{$p->min}'");

                if(isset($p->max) && mb_strlen($p->value) > $p->max)
                    throw new BadRequestException($p->name."LengthMaxExceeded", "Parameter '{$p->name}' cannot be greater than '{$p->max}'");

                if(isset($p->pattern))
                {
                    $p->pattern = self::extractPattern($p, $p->pattern);

                    if(isset($p->pattern) && preg_match($p->pattern, $p->value) == 0)
                        throw new BadRequestException($p->name."ValuePatternFailure", "Parameter '{$p->name}' does not match '{$p->pattern}'"); 
                }
                
                break;
        }
    }
}