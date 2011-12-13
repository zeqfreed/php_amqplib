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
use Amqp\Wire\Exception;;

class Writer
{
    private $_out = '';
    private $_bits = array();
    private $_bitCount = 0;

    private static function chrbytesplit($x, $bytes)
    {
        return array_map('chr', Writer::bytesplit($x, $bytes));
    }

    /**
     * Splits number (could be either int or string) into array of byte
     * values (represented as integers) in big-endian byte order.
     */
    private static function bytesplit($x, $bytes)
    {
        if (is_int($x)) {
            if ($x < 0) {
                $x = sprintf("%u", $x);
            }
        }
                
        $res = array();
        for($i = 0; $i < $bytes; $i++) {
            $b = bcmod($x,'256');
            array_unshift($res, (int)$b);
            $x = bcdiv($x, '256', 0);
        }
        
        if ($x != 0) {
            throw new Exception("Value too big to split");
        }
        
        return $res;
    }
    
    private function flushbits()
    {
        if (count($this->_bits) > 0) {
            $this->_out .= implode("", array_map('chr', $this->_bits));
            $this->_bits = array();
            $this->_bitCount = 0;
        }
    }

    /**
     * Get what's been encoded so far.
     */
    public function getvalue()
    {
        $this->flushbits();
        return $this->_out;
    }

    /**
     * Write a plain Python string, with no special encoding.
     */
    public function write($s)
    {
        $this->flushbits();
        $this->_out .= $s;
    }

    /**
     * Write a boolean value.
     */
    public function writeBit($b)
    {
        if ($b) {
            $b = 1;
        } else {
            $b = 0;
        }
        
        $shift = $this->_bitCount % 8;
        if ($shift == 0) {
            $last = 0;
        } else {
            $last = array_pop($this->_bits);
        }
        
        $last |= ($b << $shift);
        array_push($this->_bits, $last);
            
        $this->_bitCount += 1;
    }

    /**
     * Write an integer as an unsigned 8-bit value.
     */
    public function writeOctet($n)
    {
        if ($n < 0 || $n > 255) {
            throw new Exception('Octet out of range 0..255');
        }
        
        $this->flushbits();
        $this->_out .= chr($n);
    }

    /**
     * Write an integer as an unsigned 16-bit value.
     */
    public function writeShort($n)
    {
        if ($n < 0 ||  $n > 65535) {
            throw new Exception('Octet out of range 0..65535');
        }
        
        $this->flushbits();
        $this->_out .= pack('n', $n);
    }

    /**
     * Write an integer as an unsigned 32-bit value.
     */
    public function writeLong($n)
    {
        $this->flushbits();
        $this->_out .= implode("", Writer::chrbytesplit($n, 4));
    }

    private function writeSignedLong($n)
    {
        $this->flushbits();
        // although format spec for 'N' mentions unsigned
        // it will deal with sinned integers as well. tested.
        $this->_out .= pack('N', $n);
    }

    /**
     * Write an integer as an unsigned 64-bit value.
     */
    public function writeLongLong($n)
    {
        $this->flushbits();
        $this->_out .= implode("", Writer::chrbytesplit($n, 8));
    }

    /**
     * Write a string up to 255 bytes long after encoding.
     * Assume UTF-8 encoding.
     */
    public function writeShortStr($s)
    {
        $this->flushbits();
        if (strlen($s) > 255) {
            throw new Exception('String too long');
        }
        
        $this->writeOctet(strlen($s));
        $this->_out .= $s;
    }

    /*
     * Write a string up to 2**32 bytes long.  Assume UTF-8 encoding.
     */
    public function writeLongStr($s)
    {
        $this->flushbits();
        $this->writeLong(strlen($s));
        $this->_out .= $s;
    }

    /**
     * Write unix time_t value as 64 bit timestamp.
     */
   public function writeTimestamp($v)
   {
       $this->writeLongLong($v);
   }

   /**
    * Write PHP array, as table. Input array format: keys are strings,
    * values are (type,value) tuples.
    */
    public function writeTable($d)
    {
        $this->flushbits();
        $tableData = new Writer();
        
        foreach($d as $k => $va) {
            list($ftype, $v) = $va;
            $tableData->writeShortStr($k);
            
            if ('S' == $ftype) {
                $tableData->write('S');
                $tableData->writeLongStr($v);
            } else if('I' == $ftype) {
                $tableData->write('I');
                $tableData->writeSignedLong($v);
            } else if('D' == $ftype) {
                // 'D' type values are passed as Simple\Amqp\Decimal instances.
                $tableData->write('D');
                $tableData->writeOctet($v->e);
                $tableData->writeSignedLong($v->n);
            } else if('T' == $ftype) {
                $tableData->write('T');
                $tableData->writeTimestamp($v);
            } else if('F' == $ftype) {
                $tableData->write('F');
                $tableData->writeTable($v);
            }
        }
        
        $tableData = $tableData->getvalue();
        $this->writeLong(strlen($tableData));
        $this->write($tableData);
    }
}
