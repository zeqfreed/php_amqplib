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
use Amqp\Wire\TimedOutException;
use Amqp\Wire\Decimal;
use Amqp\Wire\BufferedInput;

class Reader
{
    private $_str;
    private $_sock;
    private $_offset;
    private $_bitCount;
    private $_is64bit;
    private $_bufferReadTimeout;

    public function __construct($str, $sock = null)
    {
        $this->_str = $str;

        if (null != $sock) {
            $this->_sock = new BufferedInput($sock);
        } else {
            $this->_sock = null;
        }

        $this->_offset = 0;
        $this->_bitCount = 0;
        $this->_bits = 0;

        if (((int) 4294967296) != 0) {
            $this->_is64bit = true;
        } else {
            $this->_is64bit = false;
        }

        if(!function_exists("bcmul")) {
            throw new Exception("'bcmath' module required");
        }

        $this->_bufferReadTimeout = 5; // seconds
    }

    public function close()
    {
        if ($this->_sock) {
            $this->_sock->close();
        }
    }

    public function read($n)
    {
        $this->_bitCount = 0;
        $this->_bits = 0;
        return $this->rawRead($n);
    }

    private function rawRead($n)
    {
        if (null !== $this->_sock) {
            $res = '';
            $read = 0;

            $start = time();
            while ($read < $n
                   && !feof($this->_sock->getSock())
                   && (false !== ($buf = fread($this->_sock->getSock(), $n - $read)))) {
                // Check for timeout
                $info = stream_get_meta_data($this->_sock->getSock());
                if ($info['timed_out']) {
                    throw new TimedOutException();
                }

                if ('' == $buf) {
                    usleep(100);
                } else {
                    $start = time();
                }

                $read += strlen($buf);
                $res .= $buf;
            }

            if(strlen($res) != $n) {
                throw new Exception(sprintf("Error reading data. Recevived %d instead of expected %d bytes", strlen($res), $n));
            }
            $this->_offset += $n;

        } else {
            if(strlen($this->_str) < $n) {
                throw new Exception(sprintf("Error reading data. Requested %d bytes while string buffer has only %d", $n, strlen($this->str)));
            }

            $res = substr($this->_str, 0, $n);
            $this->_str = substr($this->_str, $n);
            $this->_offset += $n;
        }

        return $res;
    }

    public function readBit()
    {
        if($this->_bitCount <= 0) {
            $this->_bits = ord($this->rawRead(1));
            $this->_bitCount = 8;
        }

        $result = ($this->_bits & 1) == 1;
        $this->_bits >>= 1;
        $this->_bitCount -= 1;
        return $result;
    }

    public function readOctet()
    {
        $this->_bitCount = 0;
        $this->_bits = 0;
        list(,$res) = unpack('C', $this->rawRead(1));
        return $res;
    }

    public function readShort()
    {
        $this->_bitCount = 0;
        $this->bits = 0;
        list(,$res) = unpack('n', $this->rawRead(2));
        return $res;
    }

    /**
     * Reads 32 bit integer in big-endian byte order.
     *
     * On 64 bit systems it will return always usngined int
     * value in 0..2^32 range.
     *
     * On 32 bit systems it will return signed int value in
     * -2^31...+2^31 range.
     *
     * Use with caution!
     */
    public function readPhpInt()
    {
        list(,$res) = unpack('N', $this->rawRead(4));

        if ($this->_is64bit) {
            $sres = sprintf ("%u", $res);
            return (int) $sres;
        } else {
            return $res;
        }
    }

    // PHP does not have unsigned 32 bit int,
    // so we return it as a string
    public function readLong()
    {
        $this->_bitCount = 0;
        $this->bits = 0;
        list(,$res) = unpack('N', $this->rawRead(4));
        $sres = sprintf("%u", $res);
        return $sres;
    }

    private function readSignedLong()
    {
        $this->_bitCount = 0;
        $this->bits = 0;

        // In PHP unpack('N') always return signed value,
        // on both 32 and 64 bit systems!
        list(,$res) = unpack('N', $this->rawRead(4));
        return $res;
    }

    // Even on 64 bit systems PHP integers are singed.
    // Since we need an unsigned value here we return it
    // as a string.
    public function readLongLong()
    {
        $this->_bitCount = 0;
        $this->_bits = 0;
        $hi = unpack('N', $this->rawRead(4));
        $lo = unpack('N', $this->rawRead(4));

        // workaround signed/unsigned braindamage in php
        $hi = sprintf ("%u", $hi[1]);
        $lo = sprintf ("%u", $lo[1]);

        return bcadd(bcmul($hi, "4294967296"), $lo);
    }

    /**
     * Read a utf-8 encoded string that's stored in up to 255 bytes
     * @return string
     */
    public function readShortStr()
    {
        $this->_bitCount = 0;
        $this->bits = 0;
        list(,$slen) = unpack('C', $this->rawRead(1));
        return $this->rawRead($slen);
    }

    /**
     * Read a string that's up to 2**32 bytes, the encoding
     * isn't specified in the AMQP spec, so just return it as
     * a plain PHP string.
     */
    public function readLongStr()
    {
        $this->_bitCount = 0;
        $this->_bits = 0;
        $slen = $this->readPhpInt();
        if ($slen < 0) {
            throw new Exception("String is longer than supported on this platform");
        }

        return $this->rawRead($slen);
    }

    /**
     * Read and AMQP timestamp, which is a 64-bit integer representing
     * seconds since the Unix epoch in 1-second resolution.
     */
    function readTimestamp()
    {
        return $this->readLongLong();
    }

    /**
     * Read an AMQP table, and return as a PHP array. keys are strings,
     * values are (type,value) tuples.
     */
    public function readTable()
    {
        $this->_bitCount = 0;
        $this->_bits = 0;

        $tlen = $this->readPhpInt();
        if ($tlen < 0) {
            throw new Exception("Table is longer than supported");
        }

        $tableData = new Reader($this->rawRead($tlen));
        $result = array();

        while ($tableData->tell() < $tlen) {
            $name = $tableData->readShortStr();
            $ftype = $tableData->rawRead(1);

            if ('S' == $ftype) {
                $val = $tableData->readLongStr();
            } else if ('I' == $ftype) {
                $val = $tableData->readSignedLong();
            } else if('D' == $ftype) {
                $e = $tableData->readOctet();
                $n = $tableData->readSignedLong();
                $val = new Decimal($n, $e);
            } else if('T' == $ftype) {
                $val = $tableData->readTimestamp();
            } else if('F' == $ftype) {
                $val = $tableData->readTable(); // recursion
            } else {
                $val = null;
            }

            $result[$name] = array($ftype, $val);
        }

        return $result;
    }

    protected function tell()
    {
        return $this->_offset;
    }
}
