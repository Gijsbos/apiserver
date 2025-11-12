<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

use Attribute;

/**
 * ReturnFilter
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ReturnFilter extends RouteAttribute
{
    public function __construct(private array $filter)
    { }

    /**
     * santizeReturnDataKey
     */
    public static function santizeReturnDataKey(string $value) : string
    {
        return preg_replace("/[^\w\-]/", "", $value);
    }

    /**
     * getIncludeFilter
     */
    private function getIncludeFilter() : array
    {
        $include = [];

        foreach($this->filter as $key => $value)
        {
            if(is_int($key))
            {
                // Exclude '!' ignore operator and empty strings
                if(strpos($value, "!") === false && strlen($value) > 0)
                    array_push($include, self::santizeReturnDataKey($value));
            }
            else
            {
                if(is_array($value))
                {
                    // Recursively call filter one level deeper
                    $value = self::getIncludeFilter($value);

                    if(strpos($key, "?") !== false)
                    {
                        $key = self::santizeReturnDataKey($key);

                        if(count($value))
                        {
                            $include[$key] = $value;
                        }
                        else
                            array_push($include, $key);
                    }
                    else
                    {
                        $include[$key] = $value;
                    }
                }
            }
        }

        return $include;
    }

    /**
     * getExcludeFilter
     */
    private function getExcludeFilter()
    {
        return array_filter(array_map_assoc(function($key, $value)
        {
            if(is_array($value))
                $value = self::getExcludeFilter($value);

            if(is_int($key) && is_string($value))
                $value = strpos($value, "!") !== false ? self::santizeReturnDataKey($value) : null;

            if(is_string($key))
            {
                if(strpos($key, "!") === false && !is_array($value))
                {
                    // Show warning? Comma missing, malformed?    
                }

                if(strpos($key, "!") !== false)
                    $key = self::santizeReturnDataKey($key);

                else if(is_array($value) && count($value))
                    $key = self::santizeReturnDataKey($key);

                else
                    $key = null;
            }

            return [$key, $value];
        }, $this->filter));
    }

    /**
     * applyFilter
     */
    public function applyFilter(array $data) : array
    {
        // Clear the optional sign '?' which is used for testing
        $includeFilter = self::getIncludeFilter();

        // Get filtered data
        if(count($includeFilter))
            $data = array_filter_keys_recursive($data, $includeFilter, true, true);

        // Get exclude filter
        $excludeFilter = self::getExcludeFilter();

        // Get filtered data
        if(count($excludeFilter))
            $data = array_filter_keys_recursive($data, $excludeFilter, true, false);

        // Return data
        return $data;
    }
}