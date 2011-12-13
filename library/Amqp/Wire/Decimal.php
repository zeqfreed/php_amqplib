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

class Decimal
{
	private $_e;
	private $_n;
	
    public function __construct($n, $e)
    {
        if ($e < 0) {
            throw new Exception("Decimal exponent value must be unsigned");
        }
        
        $this->_n = $n;
        $this->_e = $e;
    }

    public function asBCValue()
    {
        return bcdiv($this->_n, bcpow(10, $this->_e));
    }
}
