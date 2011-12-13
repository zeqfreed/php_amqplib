<?php

/**
 * 
 * AMQP client library (protocol version 0.8)
 * 
 * @source https://github.com/zeqfreed/php_amqplib
 * @author Artem Kozlov <zeqfreed@gmail.com>
 * @version 0.1
 *
 * This code is a rewrite of Simple AMQP library:
 * http://code.google.com/p/php-amqplib/
 * Vadim Zaliva <lord@crocodile.org>
 * 
 */

namespace Amqp\Wire;
use Amqp\Wire\Exception;
use Amqp\Wire\Reader;

/**
 * Abstract base class for AMQP content
 * Subclasses should override the 'properties' attribute.
 */
class GenericContent
{    
    protected $_basePropTypes = array(
        "dummy" => "shortStr"
    );
    
    private $_propTypes;
    public $properties;
    public $deliveryInfo;

    public function __construct($props, $propTypes=null)
    {
        if (null !== $propTypes) {
            $this->_propTypes = $propTypes;
        } else {
            $this->_propTypes = $this->_basePropTypes;
        }
        
        $d = array();
        if ($props) {
            $d = array_intersect_key($props, $this->_propTypes);
        } else {    
            $d = array();
        }
        
        $this->properties = $d;
    }

    public function get($name)
    {
        if (array_key_exists($name, $this->properties)) {
            return $this->properties[$name];
        }
        
        if (isset($this->deliveryInfo)
            && array_key_exists($name, $this->deliveryInfo)) {
                return $this->deliveryInfo[$name];
        }
        
        throw new WireException(sprintf("Property %s not found", $name));
    }

    /**
     * Given the raw bytes containing the property-flags and
     * property-list from a content-frame-header, parse and insert
     * into a dictionary stored in this object as an attribute named
     * 'properties'.
     */
    public function loadProperties($rawBytes)
    {
        $r = new Reader($rawBytes);

        // Read 16-bit shorts until we get one with a low bit set to zero
        $flags = array();
        
        while (1) {
            $flagBits = $r->readShort();
            array_push($flags, $flagBits);
            if (($flagBits & 1) == 0) {
                break;
            }
        }

        $shift = 0;
        $d = array();
        
        foreach ($this->_propTypes as $key => $propType) {
            if ($shift == 0) {
                if(!$flags) {
                    break;
                }
                
                $flagBits = array_shift($flags);
                $shift = 15;
            }
            
            if ($flagBits & (1 << $shift)) {
                $d[$key] = call_user_func(array($r, "read" . ucfirst($propType)));
            }
            $shift -= 1;
        }
        
        $this->properties = $d;
    }

    /**
     * serialize the 'properties' attribute (a dictionary) into the
     * raw bytes making up a set of property flags and a property
     * list, suitable for putting into a content frame header.
     */
    public function serializeProperties()
    {
        $shift = 15;
        $flagBits = 0;
        $flags = array();
        $rawBytes = new Writer();
        
        foreach ($this->_propTypes as $key => $propType) {
            if (array_key_exists($key, $this->properties)) {
                $val = $this->properties[$key];
            } else {
                $val = null;
            }
            
            if (null !== $val) {
                if ($shift == 0) {
                    array_push($flags, $flagBits);
                    $flagBits = 0;
                    $shift = 15;
                }
                
                $flagBits |= (1 << $shift);
                if ($propType != "bit") {
                    call_user_func(array($rawBytes, "write" . ucfirst($propType)), $val);
                }
            }
            $shift -= 1;
        }
        
        array_push($flags, $flagBits);
        $result = new Writer();
        
        foreach($flags as $flagBits) {
            $result->writeShort($flagBits);
        }
        
        $result->write($rawBytes->getvalue());
        return $result->getvalue();
    }
}
