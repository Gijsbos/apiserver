<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;
use Exception;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;

/**
 * ReturnFilter
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ReturnFilter extends RouteAttribute
{
    const DOC_COMMENT_VAR_SYMBOL_SEARCH = "@var ";

    public function __construct(private string|array $filter)
    {
        $this->filter = $this->initFilterData($this->filter);
    }

    /**
     * getClassPropertyNames
     */
    private function getClassPropertyNames(string $className)
    {
        return array_map(fn($p) => $p->getName(), new ReflectionClass($className)->getProperties(ReflectionProperty::IS_PUBLIC));
    }

    /**
     * isPrimitiveType
     */
    private function isPrimitiveType(string $type)
    {
        return in_array($type, ["string","int","float","double","mixed","bool"]);
    }

    /**
     * isUnionType
     */
    private function isUnionType(string $type) : bool
    {
        return str_contains($type, "|");
    }

    /**
     * getClassNameFromType
     */
    private function getClassNameFromType(string $parentClassName, string $type)
    {
        $namespace = new ReflectionClass($parentClassName)->getNamespaceName();

        $typeClassName = trim((strlen($namespace) ? $namespace . "\\" . $type : $type));

        if(str_ends_with($typeClassName, "[]"))
        {
            $typeClassName = substr($typeClassName, 0, strlen($typeClassName) - 2);
        }

        if(class_exists($typeClassName))
        {
            return $typeClassName;
        }

        return null;
    }

    /**
     * resolveDocPropertyClassName
     */
    private function resolveDocPropertyClassName(string $className, string $propertyName)
    {
        $propertyDocComment = new ReflectionProperty($className, $propertyName)->getDocComment();

        if($propertyDocComment !== false)
        {
            $varPos = strpos($propertyDocComment, self::DOC_COMMENT_VAR_SYMBOL_SEARCH);

            if($varPos !== false)
            {
                $type = explode(" ", substr($propertyDocComment, $varPos + strlen(self::DOC_COMMENT_VAR_SYMBOL_SEARCH)))[0];

                if($this->isPrimitiveType($type) || $this->isUnionType($type))
                    return null;

                return $this->getClassNameFromType($className, $type);
            }
        }

        return null;
    }

    /**
     * initFilterData
     * 
     * @param string|array ClassName or Array with property names
     */
    private function initFilterData(string|array $data, ?string $targetClassName = null)
    {
        if(is_string($data)) // ClassName
        {
            if(!class_exists($data))
                throw new InvalidArgumentException("Invalid filter input, expected array|className");

            $classPropertyNames = $this->getClassPropertyNames($data);

            return $this->initFilterData($classPropertyNames, $data);
        }
        else
        {
            foreach($data as $i => $propertyName)
            {
                if(is_string($propertyName))
                {
                    if(is_string($targetClassName)) // Class Context Set
                    {
                        if(property_exists($targetClassName, $propertyName))
                        {
                            $resolvedPropertyClassName = $this->resolveDocPropertyClassName($targetClassName, $propertyName);
                            
                            if(is_string($resolvedPropertyClassName))
                            {
                                $classPropertyNames = $this->getClassPropertyNames($resolvedPropertyClassName);

                                $resolvedFilterData = $this->initFilterData($classPropertyNames, $resolvedPropertyClassName);

                                // Replace the "$i => $propertyName" entry with "$propertyName => [...]"
                                // in place, so the property keeps its original position in the array.
                                $rebuilt = [];

                                foreach($data as $existingKey => $existingValue)
                                {
                                    if($existingKey === $i)
                                        $rebuilt[$propertyName] = $resolvedFilterData;
                                    else
                                        $rebuilt[$existingKey] = $existingValue;
                                }

                                $data = $rebuilt;
                            }
                        }
                    }
                    else
                    {
                        if(class_exists($propertyName))
                        {
                            $classPropertyNames = $this->getClassPropertyNames($propertyName);

                            $data[$i] = $this->initFilterData($classPropertyNames, $propertyName);
                        }
                    }
                }
            }
            
            return $data;
        }
    }

    /**
     * getFilter
     */
    public function getFilter()
    {
        return $this->filter;
    }

    /**
     * santizeReturnDataKey
     */
    public static function santizeReturnDataKey(string $value) : string
    {
        return preg_replace("/[^\w\-]/", "", $value);
    }

    /**
     * applyFilter
     */
    public function applyFilter(array|object $data, null|array &$filter = null) : array
    {
        $filter = $filter ?? $this->filter ?? [];

        // Deal with list arrays
        foreach($data as $key => $value)
        {
            // Sequential value
            if(is_int($key))
            {
                
                // Value is List Item, Keep Filter The Same, Go n+1 deeper
                if(is_array($value))
                {
                    $data[$key] = $this->applyFilter($value, $filter);   
                }
            }

            // Assoc value
            else
            {
                $filterBehaviour = "include";
                $filterType = "string";

                if(in_array($key, $filter)) // e.g. 'foo' from data in filter ['foo', 'bar']
                {
                    $filterKey = $key;
                }
                else if(array_key_exists($key, $filter)) // e.g. 'foo' from data in filter ['foo' => [], 'bar' => []]
                {
                    $filterKey = $key;
                    $filterType = "array";
                }
                else if(in_array("!$key", $filter) || in_array("$key!", $filter))
                {
                    
                    $filterKey = in_array("!$key", $filter) ? "!$key" : "$key!";
                    $filterBehaviour = "exclude";
                }
                else if(array_key_exists("!$key", $filter) || array_key_exists("$key!", $filter))
                {
                    $filterKey = array_key_exists("!$key", $filter) ? "!$key" : "$key!";
                    $filterBehaviour = "exclude";
                    $filterType = "array";
                }
                else if(in_array("?$key", $filter) || in_array("$key?", $filter))
                {
                    $filterKey = in_array("?$key", $filter) ? "?$key" : "$key?";
                }
                else if(array_key_exists("?$key", $filter) || array_key_exists("$key?", $filter))
                {
                    $filterKey = array_key_exists("?$key", $filter) ? "?$key" : "$key?";
                    $filterType = "array";
                }
                else
                {
                    $filterKey = $key; // Key not found in filter, default is REMOVE
                    $filterBehaviour = "exclude";
                }

                // Filter is set and represents the actual filter key that can be used to filter out stuff from data
                if($filterKey !== null)
                {
                    if($filterType == "string")
                    {
                        if($filterBehaviour == "include")
                        {
                            $data[$key] = $data[$key]; // Retains the WHOLE assoc array, since filterType is 'string'
                        }
                        else
                        {
                            unset($data[$key]); // Exclude
                        }
                    }
                    else if($filterType == "array")
                    {
                        if(is_array($value))
                        {
                            if($filterBehaviour == "include")
                            {
                                $data[$key] = $this->applyFilter($data[$key], $filter[$filterKey]); // Retains the WHOLE assoc array, since filterType is 'string'   
                            }
                            else
                            {
                                unset($data[$key]); // Exclude
                            }
                        }
                        else
                        {
                            if($filterBehaviour == "include")
                            {
                                $data[$key] = $data[$key]; // Retains the WHOLE assoc array, since filterType is 'string'
                            }
                            else
                            {
                                unset($data[$key]); // Exclude
                            }
                        }
                    }
                    else 
                        throw new Exception("Invalid filter type value $filterType");
                }
            }
        }

        return $data;
    }
}