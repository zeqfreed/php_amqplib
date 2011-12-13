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
use Amqp\ChannelException;
use Amqp\Protocol\MethodNames;
use Amqp\Wire\Reader;

abstract class AbstractChannel
{	
	protected $_connection;
	protected $_channelId;
	
	/**
	 * Lower level queue for frames
	 * @var array
	 */
	public $_frameQueue = array();
	
	/**
	* Higher level queue for frames
	* @var array
	*/	
	public $_methodQueue = array();
	
	protected $_autoDecode = false;
	
    private $_contentMethods = array(
    	MethodNames::METHOD_CHANNEL_BASIC_DELIVER,
    	MethodNames::METHOD_CHANNEL_BASIC_GET_OK,
    );
    
    private $_closeMethods = array(
    	MethodNames::METHOD_CONNECTION_CLOSE,
    	MethodNames::METHOD_CHANNEL_CLOSE,
    );
    
    /**
     * Should be defined by descendants 
     * @var array
     */
    protected $_methodMap = array(
    );

    public function __construct($connection, $channelId)
    {
        $this->_connection = $connection;
        $this->_channelId = $channelId;
        $this->_connection->_channels[$channelId] = $this;
    }

    function dispatch($methodSig, $args, $content)
    {
        if(!array_key_exists($methodSig, $this->_methodMap)) {
			throw new ChannelException(sprintf("Unknown AMQP method %s", $methodSig));
		}
        
        $amqpMethod = $this->_methodMap[$methodSig];
        if (null == $content) {
            return call_user_func(array($this, $amqpMethod), $args);
        } else {
            return call_user_func(array($this, $amqpMethod), $args, $content);
        }
    }

    function nextFrame()
    {
        if (count($this->_frameQueue) > 0) {
			return array_pop($this->_frameQueue);
        }
        
        return $this->_connection->waitChannel($this->_channelId);
    }
    
    protected function sendMethodFrame($methodSig, $args = '')
    {
        $this->_connection->sendChannelMethodFrame($this->_channelId, $methodSig, $args);
    }

    function waitContent()
    {
        $frm = $this->nextFrame();
        $frameType = $frm[0];
        $payload = $frm[1];
        
        if ($frameType != 2) {
			throw new ChannelException("Expected Content header");
        }

        $payloadReader = new Reader(substr($payload, 0, 12));
        $classId = $payloadReader->readShort();
        $weight = $payloadReader->readShort();

        $bodySize = $payloadReader->readLongLong();
        $msg = new Message();
        $msg->loadProperties(substr($payload, 12));

        $bodyParts = array();
        $bodyReceived = 0;
        
        while(bccomp($bodySize, $bodyReceived) == 1) {
            $frm = $this->nextFrame();
            $frameType = $frm[0];
            $payload = $frm[1];
            
            if ($frameType != 3) {
				throw new ChannelException(sprintf("Expected Content body, received frame type %s", $frame_type));
            }
            
            $bodyParts[] = $payload;
            $bodyReceived = bcadd($bodyReceived, strlen($payload));
        }

        $msg->body = implode("", $bodyParts);

        if ($this->_autoDecode and isset($msg->contentEncoding)) {
            try {
                $msg->body = $msg->body->decode($msg->contentEncoding);
            } catch (Exception $e) {
                // Ignoring errors that occur during encoding.
                // XXX: Why?
            }
        }
        
        return $msg;
    }
    
    /**
     * Wait for some expected AMQP methods and dispatch them
     * Unexpected methods are queued up for later calls
     */
    public function wait($allowedMethods = null)
    {
        foreach ($this->_methodQueue as $qk => $queuedMethod) {
            $methodSig = $queuedMethod[0];
            if (null == $allowedMethods || in_array($methodSig, $allowedMethods)) {
                unset($this->methodQueue[$qk]);                
                return $this->dispatch($queuedMethod[0], $queuedMethod[1], $queuedMethod[2]);
            }
        }
        
        while (1) {
            $frm = $this->nextFrame();
            $frameType = $frm[0];
            $payload = $frm[1];
            
            if ($frameType != 1) {
                throw new ChannelException(sprintf("Expected AMQP method, received frame type %s", $frameType));
            }

            if (strlen($payload) < 4) {
                throw new ChannelException("Method frame is too short");
            }
            
            $methodSigArray = unpack("n2", substr($payload, 0, 4));
            $methodSig = "" . $methodSigArray[1] . "," . $methodSigArray[2]; 
            $args = new Reader(substr($payload, 4));
            
            if (in_array($methodSig, $this->_contentMethods)) {
                $content = $this->waitContent();
            } else {
                $content = null;
            }
            
            if (null == $allowedMethods
                || in_array($methodSig, $allowedMethods)
                || in_array($methodSig, $this->_closeMethods)) {
                return $this->dispatch($methodSig, $args, $content);
            }
            
            array_push($this->methodQueue, array($methodSig, $args, $content));
            
            if ($this->_timedOut()) {
            	throw new Exception('Timed out');
            }
        }
    }
}
