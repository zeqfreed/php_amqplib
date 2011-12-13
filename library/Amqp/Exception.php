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
use Amqp\Protocol\MethodNames;

class Exception extends \Exception
{
	public $amqpReplyCode;
	public $amqpReplyText;
	public $amqpMethodSig;
	
	public function __construct($replyCode, $replyText = null, $methodSig = null)
	{
		parent::__construct();
	
		$this->amqpReplyCode = $replyCode;
		$this->amqpReplyText = $replyText;
		$this->amqpMethodSig = $methodSig;
	
		$mn = MethodNames::map($methodSig);
		
		$this->args = array(
			$replyCode,
			$replyText,
			$methodSig,
			$mn
		);
	}
}

class ConnectionException extends Exception
{
	//
}
