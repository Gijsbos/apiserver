<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

use gijsbos\ApiServer\Interfaces\RouteParamInterface;
use ReflectionClass;

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
        $this->pattern = @$opts["pattern"];
        $this->values = @$opts["values"];
        $this->required = @$opts["required"] ?? true;
        $this->default = @$opts["default"];
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