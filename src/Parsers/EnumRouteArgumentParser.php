<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Parsers;

use SimpleXMLElement;

/**
 * EnumRouteArgumentParser
 */
class EnumRouteArgumentParser
{
    public static function parse(string $argumentName, string $enumClass, mixed $value)
    {
        $enumValue = $enumClass::tryFrom($value);

        if($enumValue === null)
        {
            $accepted = implode("|", array_column($enumClass::cases(), "value"));
            $shortName = substr((string) strrchr($enumClass, "\\"), 1) ?: $enumClass;

            throw new BadRequestException(
                $argumentName."ValueInvalid",
                "Failed to convert value '{$value}' of parameter '{$argumentName}' to required type '{$shortName}'; must be one of [{$accepted}]"
            );
        }

        return $enumValue;
    }
}