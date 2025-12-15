<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

use gijsbos\ApiServer\Attributes\Route;
use gijsbos\ApiServer\Interfaces\RouteParamInterface;
use ReflectionClass;

/**
 * RouteParam
 */
class RouteParam implements RouteParamInterface
{
    public string $name = "";
    public null|string $description = null;
    public null|Route $route = null;
    public mixed $value = null;
    public null|string $type = null;
    public null|string $customType = null;
    public mixed $min = null;
    public mixed $max = null;
    public null|array|string $pattern = null;
    public null|array $values = null;
    public bool $required = false;
    public mixed $default = null;
    public array $opts = [];

    public function __construct(
        array $opts = [],
        null|string $type = null,
        null|string $customType = null,
        mixed $min = null,
        mixed $max = null,
        null|array|string $pattern = null,
        null|array $values = null,
        null|bool $required = null,
        mixed $default = null,
        null|string $description = null,
        null|string $docs = null,
    )
    {
        $this->type = $type ?? $this->extractFromOpts("type", $opts);
        $this->customType = $customType ?? $this->extractFromOpts("customType", $opts);
        $this->min = $min ?? $this->extractFromOpts("min", $opts);
        $this->max = $max ?? $this->extractFromOpts("max", $opts);
        $this->pattern = $pattern ?? $this->extractFromOpts("pattern", $opts);
        $this->values = $values ?? $this->extractFromOpts("values", $opts);
        $this->required = $required ?? $this->extractFromOpts("required", $opts) ?? true;
        $this->default = $default ?? $this->extractFromOpts("default", $opts);
        $this->description = $description ?? $this->extractFromOpts("description", $opts) ?? $docs ?? $this->extractFromOpts("docs", $opts);
        $this->opts = $opts;
    }

    private function extractFromOpts(string $key, &$opts)
    {
        if(array_key_exists($key, $opts))
        {
            $value = $opts[$key];
            unset($opts[$key]);
            return $value;
        }
        return null;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getDescription()
    {
        return $this->description;
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
        return $this->opts;
    }

    public static function createWithoutConstructorFromObject($object, string $name, Route $route, mixed $value = null, mixed $type = null, mixed $customType = null)
    {
        $object->name = $name;
        $object->route = $route;        
        $object->type = $type;
        $object->customType = $customType; // 'type' can be used as shorthand for 'customtype', in here we set the right parameters where they belong
        $object->value = $value;
        return $object;
    }

    public static function createWithoutConstructor(string $name, Route $route, mixed $value = null, mixed $type = null)
    {
        return self::createWithoutConstructorFromObject((new ReflectionClass(__CLASS__))->newInstanceWithoutConstructor(), $name, $route, $value, $type);
    }
}