<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use gijsbos\ApiServer\Classes\RouteParam;
use gijsbos\Http\Exceptions\BadRequestException;

/**
 * RouteParamValidator
 */
abstract class RouteParamValidator
{
    const WORD_PATTERN = "/^\w+$/";
    const URI_PATTERN = "/^https?:\/\//";

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

    public static function validate(RouteParam $p)
    {
        if($p->value == null)
        {
            if($p->isRequired())
                throw new BadRequestException($p->name."InputInvalid", "Parameter {$p->name} is required");
            else
                return true;
        }

        if(is_array($p->values))
        {
            if(!in_array($p->value, $p->values))
                throw new BadRequestException($p->name."ValueInvalid", "Parameter {$p->name} value does not contain " . implode("|", $p->values));
        }

        $type = is_string($p->type) ? $p->type : self::getTypeFromValue($p->value);

        switch($type)
        {
            case "int":
            case "float":
            case "double":
                if(isset($p->min) && $p->value < $p->min)
                    throw new BadRequestException($p->name."ValueMinExceeded", "Parameter {$p->name} cannot be less than {$p->min}");

                if(isset($p->max) && $p->value > $p->max)
                    throw new BadRequestException($p->name."ValueMaxExceeded", "Parameter {$p->name} cannot be greater than {$p->max}");

            case "string":
                if(isset($p->min) && mb_strlen($p->value) < $p->min)
                    throw new BadRequestException($p->name."LengthMinExceeded", "Parameter {$p->name} cannot be less than {$p->min}");

                if(isset($p->max) && mb_strlen($p->value) > $p->max)
                    throw new BadRequestException($p->name."LengthMaxExceeded", "Parameter {$p->name} cannot be greater than {$p->max}");

                if(isset($p->pattern) && preg_match($p->pattern, $p->value) == 0)
                    throw new BadRequestException($p->name."ValuePatternFailure", "Parameter {$p->name} does not match {$p->pattern}"); 
        }
    }
}