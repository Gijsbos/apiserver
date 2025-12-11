<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;
use Exception;

/**
 * ReturnFilter
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ReturnFilter extends RouteAttribute
{
    public function __construct(private array $filter)
    { }

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
    public function applyFilter(array $data, null|array &$filter = null) : array
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