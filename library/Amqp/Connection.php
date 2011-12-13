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

namespace Amqp;
use Amqp\Exception;
use Amqp\ConnectionException;
use Amqp\Protocol\MethodNames;
use Amqp\Wire\Reader;
use Amqp\Wire\Writer;
use Amqp\AbstractChannel;
use Amqp\Channel;

class Connection extends AbstractChannel
{
    public $_amqpProtocolHeader = "AMQP\x01\x01\x09\x01";
    public $_libraryProperties = array(
        "library" => array('S', "Simple AMQP Library"),
        "library_version" => array('S', "0.0.1")
    );

    protected $_methodMap = array(
        MethodNames::METHOD_CONNECTION_START => 'start',
        MethodNames::METHOD_CONNECTION_SECURE => 'secure',
        MethodNames::METHOD_CONNECTION_TUNE => 'tune',
        MethodNames::METHOD_CONNECTION_OPEN_OK => 'openOk',
        MethodNames::METHOD_CONNECTION_REDIRECT => 'redirect',
        MethodNames::METHOD_CONNECTION_CLOSE => 'connClose',
        MethodNames::METHOD_CONNECTION_CLOSE_OK => 'closeOk',
    );
    
    public $_channels = array();
    private $_channelMax = 65535;
    private $_frameMax = 131072;
    private $_heartbeat;
    private $_sock = null;
    private $_input = null;
    private $_waitTuneOk = false;
    private $_knownHosts = '';
    
    private $_versionMajor = null;
    private $_versionMinor = null;
    private $_serverProperties = null;
    private $_mechanisms = null;
    private $_locales = null;    

    public function __construct($host, $port, $user, $password, $vhost = "/", $insist = false,
                                $loginMethod = "AMQPLAIN", $loginResponse = null, $locale = "en_US",
                                $connectionTimeout = 10, $readWriteTimeout = 3)
    {
        if (null !== $user && null !== $password) {
            $loginResponse = new Writer();
            $loginResponse->writeTable(array(
                "LOGIN" => array('S', $user),
                "PASSWORD" => array('S', $password)
            ));
            $loginResponse = substr($loginResponse->getvalue(), 4); //Skip the length
        } else {
            $loginResponse = null;
        }

        $d = $this->_libraryProperties;
        while (1) {
            $this->_channels = array();
            parent::__construct($this, 0);

            $this->_channelMax = 65535;
            $this->_frameMax = 131072;

            $errstr = null;
            $errno = null;
            $this->_sock = null;
            
            if (!($this->_sock = fsockopen($host, $port, $errno, $errstr, $connectionTimeout))) {
                throw new Exception(sprintf("Error Connecting to server (%d): $s", $errno, $errstr));
            }
            
            stream_set_timeout($this->_sock, $readWriteTimeout);
            stream_set_blocking($this->_sock, 1);
            $this->_input = new Reader(null, $this->_sock);

            $this->write($this->_amqpProtocolHeader);
            $this->wait(array(MethodNames::METHOD_CONNECTION_START));
            $this->xStartOk($d, $loginMethod, $loginResponse, $locale);

            $this->_waitTuneOk = true;
            while($this->_waitTuneOk) {
                $this->wait(array(
                    MethodNames::METHOD_CONNECTION_SECURE,
                    MethodNames::METHOD_CONNECTION_TUNE,
                ));
            }

            $host = $this->xOpen($vhost, '', $insist);
            if(!$host) {
                return;
            }

            // Redirect
            fclose($this->_sock);
            $this->_sock = null;
        }
    }
    
    public function setTimeout($timeout)
    {
        $this->_timeout = $timeout;
        if (null !== $this->_sock) {
            stream_set_timeout($this->_sock, $timeout);
        }
    }
     
    public function __destruct()
    {
        if (isset($this->_input)) {
            if(null !== $this->_input) {
                $this->close();
            }
        }

        if ($this->_sock instanceof BufferedInput) {
            fclose($this->_sock);
        }
    }

    protected function write($data)
    {
        $len = strlen($data);
        
        while (1) {
            if (false === ($written = fwrite($this->_sock, $data))) {
                throw new Exception("Error sending data");
            }
            
            $len = $len - $written;
            if ($len > 0) {
                $data = substr($data, 0 - $len);
            } else {
                break;
            }
        }
    }

    protected function doClose()
    {
        if (isset($this->_input)
            && null !== $this->_input) {
            $this->_input->close();
            $this->_input = null;
        }

        if ($this->_sock instanceof BufferedInput) {
            fclose($this->_sock->getSock());
            $this->_sock = null;
        }
    }

    public function getFreeChannelId()
    {
        for($i=1; $i <= $this->_channelMax; $i++) {
            if(!array_key_exists($i, $this->_channels)) {
                return $i;
            }
        }
        
        throw new ConnectionException("No free channel ids available");
    }

    public function sendContent($channel, $classId, $weight, $bodySize,
                                $packedProperties, $body)
    {
        $pkt = new Writer();

        $pkt->writeOctet(2);
        $pkt->writeShort($channel);
        $pkt->writeLong(strlen($packedProperties) + 12);

        $pkt->writeShort($classId);
        $pkt->writeShort($weight);
        $pkt->writeLongLong($bodySize);
        $pkt->write($packedProperties);

        $pkt->writeOctet(0xCE);
        $pkt = $pkt->getvalue();
        $this->write($pkt);

        while ($body) {
            $payload = substr($body, 0, $this->_frameMax - 8);
            $body = substr($body, $this->_frameMax - 8);
            $pkt = new Writer();
    
            $pkt->writeOctet(3);
            $pkt->writeShort($channel);
            $pkt->writeLong(strlen($payload));

            $pkt->write($payload);

            $pkt->writeOctet(0xCE);
            $pkt = $pkt->getvalue();
            $this->write($pkt);
        }
    }

    protected function sendChannelMethodFrame($channel, $methodSig, $args='')
    {
        if ($args instanceof Writer) {
            $args = $args->getvalue();
        }

        $pkt = new Writer();

        $pkt->writeOctet(1);
        $pkt->writeShort($channel);
        $pkt->writeLong(strlen($args) + 4);  // 4 = length of class_id and method_id
        // in payload

        $pkt->writeShort($methodSig[0]); // class_id
        $pkt->writeShort($methodSig[1]); // method_id
        $pkt->write($args);

        $pkt->writeOctet(0xCE);
        $pkt = $pkt->getvalue();
        $this->write($pkt);
    }

    /**
    * Wait for a frame from the server
    */
    protected function waitFrame()
    {
        $frameType = $this->_input->readOctet();
        $channel = $this->_input->readShort();
        $size = $this->_input->readLong();
        $payload = $this->_input->read($size);

        $ch = $this->_input->readOctet();
        if ($ch != 0xCE) {
            throw new ConnectionException(sprintf("Framing error, unexpected byte: %x", $ch));
        }

        return array($frameType, $channel, $payload);
    }

    /**
    * Wait for a frame from the server destined for
    * a particular channel.
    */
    protected function waitChannel($channelId)
    {
        while (1) {            
            list($frameType, $frameChannel, $payload) = $this->waitFrame();
            if ($frameChannel == $channelId) {
                return array($frameType, $payload);
            }
            
            // Not the channel we were looking for.  Queue this frame
            // for later, when the other channel is looking for frames.
            array_push($this->_channels[$frameChannel]->_frameQueue, array($frameType, $payload));
            
            // If we just queued up a method for channel 0 (the Connection
            // itself) it's probably a close method in reaction to some
            // error, so deal with it right away.
            if (($frameType == 1) && ($frameChannel == 0)) {
                $this->wait();
            }
        }
    }

    /**
    * Fetch a Channel object identified by the numeric channel_id, or
    * create that object if it doesn't already exist.
    */
    public function channel($channelId = null)
    {
        if(array_key_exists($channelId, $this->_channels)) {
            return $this->_channels[$channelId];
        }
        
        return new Channel($this->_connection, $channelId);
    }

    /**
    * request a connection close
    */
    public function close($replyCode = 0, $replyText= '', $methodSig = array(0, 0))
    {
        $args = new Writer();
        $args->writeShort($replyCode);
        $args->writeShortStr($replyText);
        $args->writeShort($methodSig[0]); // class_id
        $args->writeShort($methodSig[1]); // method_id
        $this->sendMethodFrame(array(10, 60), $args);
        
        $result = $this->wait(array(
            MethodNames::METHOD_CONNECTION_CLOSE_OK,
        ));
        
        return $result;
    }

    protected function connClose($args)
    {
        $replyCode = $args->readShort();
        $replyText = $args->readShortStr();
        $classId = $args->readShort();
        $methodId = $args->readShort();
    
        $this->xCloseOk();
    
        throw new ConnectionException($replyCode, $replyText, array($classId, $methodId));
    }


    /**
    * confirm a connection close
    */
    protected function xCloseOk()
    {
        $this->sendMethodFrame(array(10, 61));
        $this->doClose();
    }

    /**
    * confirm a connection close
    */
    protected function closeOk($args)
    {
        $this->doClose();
    }

    protected function xOpen($virtualHost, $capabilities='', $insist = false)
    {
        $args = new Writer();
        $args->writeShortStr($virtualHost);
        $args->writeShortStr($capabilities);
        $args->writeBit($insist);
        $this->sendMethodFrame(array(10, 40), $args);
        
        $result = $this->wait(array(
            MethodNames::METHOD_CONNECTION_OPEN_OK,
            MethodNames::METHOD_CONNECTION_REDIRECT,
        ));
        
        return $result;
    }


    /**
    * signal that the connection is ready
    */
    protected function openOk($args)
    {
        $this->_knownHosts = $args->readShortStr();
        return null;
    }


    /**
    * asks the client to use a different server
    */
    protected function redirect($args)
    {
        $host = $args->readShortStr();
        $this->_knownHosts = $args->readShortStr();
        return $host;
    }

    /**
    * security mechanism challenge
    */
    protected function secure($args)
    {
        $challenge = $args->readLongStr();
    }

    /**
    * security mechanism response
    */
    protected function xSecureOk($response)
    {
        $args = new Writer();
        $args->writeLongStr($response);
        $this->sendMethodFrame(array(10, 21), $args);
    }

    /**
    * start connection negotiation
    */
    protected function start($args)
    {
        $this->_versionMajor = $args->readOctet();
        $this->_versionMinor = $args->readOctet();
        $this->_serverProperties = $args->readTable();
        $this->_mechanisms = explode(' ', $args->readLongStr());
        $this->_locales = explode(' ', $args->readLongStr());
    }

    protected function xStartOk($clientProperties, $mechanism, $response, $locale)
    {
        $args = new Writer();
        $args->writeTable($clientProperties);
        $args->writeShortStr($mechanism);
        $args->writeLongStr($response);
        $args->writeShortStr($locale);
        $this->sendMethodFrame(array(10, 11), $args);
    }

    /**
    * propose connection tuning parameters
    */
    protected function tune($args)
    {
        $v = $args->readShort();
        if ($v) {
            $this->_channelMax = $v;
        }
        
        $v = $args->readLong();
        if ($v) {
            $this->_frameMax = $v;
        }
        
        $this->_heartbeat = $args->readShort();        
        $this->xTuneOk($this->_channelMax, $this->_frameMax, 0);
    }

    /**
    * negotiate connection tuning parameters
    */
    protected function xTuneOk($channelMax, $frameMax, $heartbeat)
    {
        $args = new Writer();
        $args->writeShort($channelMax);
        $args->writeLong($frameMax);
        $args->writeShort($heartbeat);
        $this->sendMethodFrame(array(10, 31), $args);
        $this->_waitTuneOk = False;
    }
}
