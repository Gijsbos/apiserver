<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use SimpleXMLElement;

/**
 * ArrayToXmlParser
 */
class ArrayToXmlParser
{
    public function __construct()
    { }

    /**
     * arrayToXml
     */
    public function arrayToXml(array $data, null|SimpleXMLElement $xml = null): SimpleXMLElement
    {
        if ($xml === null)
        {
            $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><root/>');
        }

        foreach ($data as $key => $value)
        {
            if (is_array($value))
            {
                if (array_is_list($value))
                {
                    $container = $xml->addChild($key);

                    foreach ($value as $v)
                    {
                        $child = $container->addChild('item'); // repeated <item> for each entry
                        $this->arrayToXml($v, $child);
                    }
                }
                else
                {
                    $child = $xml->addChild($key);
                    $this->arrayToXml($value, $child);
                }
            }
            else
            {
                $xml->addChild($key, htmlspecialchars((string)$value));
            }
        }

        return $xml;
    }
}