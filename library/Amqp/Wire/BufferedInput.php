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

class BufferedInput
{
	private $_blockSize = 8192;
	private $_sock = null;
	private $_offset;
	private $_buffer;
	
	public function __construct($sock)
	{
		$this->_sock = $sock;
		$this->reset('');
	}

	public function read($n)
	{
		if ($this->_offset >= strlen($this->_buffer)) {
			if (!($rv = $this->populateBuffer())) {
				return $rv;
			}
		}
		
		return $this->readBuffer($n);
	}
	
	public function getSock()
	{
		return $this->_sock;
	}

	public function close()
	{
		fclose($this->_sock);
		$this->reset('');
	}

	private function readBuffer($n)
	{
		$n = min($n, strlen($this->_buffer) - $this->_offset);
		if ($n === 0) {
			// substr("", 0, 0) => FALSE, which screws up read loops that are
			// expecting non-blocking reads to return "". This avoids that edge
			// case when the buffer is empty/used up.
			return '';
		}
		
		$block = substr($this->_buffer, $this->_offset, $n);
		$this->_offset += $n;
		return $block;
	}

	private function reset($block)
	{
		$this->_buffer = $block;
		$this->_offset = 0;
	}

	private function populateBuffer()
	{
		if(feof($this->_sock)) {
			$this->reset('');
			return false;
		}

		$block = fread($this->_sock, $this->_blockSize);
		if (false !== $block) {
			$this->reset($block);
			return true;
		} else {
			return $block;
		}
	}
}
