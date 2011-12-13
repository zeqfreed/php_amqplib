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
use Amqp\Wire\GenericContent;

class Message extends GenericContent
{
    protected $_basePropTypes = array(
        "content_type" => "shortStr",
        "content_encoding" => "shortStr",
        "application_headers" => "table",
        "delivery_mode" => "octet",
        "priority" => "octet",
        "correlation_id" => "shortStr",
        "reply_to" => "shortStr",
        "expiration" => "shortStr",
        "message_id" => "shortStr",
        "timestamp" => "timestamp",
        "type" => "shortStr",
        "user_id" => "shortStr",
        "app_id" => "shortStr",
        "cluster_id" => "shortStr"
    );
    
    public $body = '';

    public function __construct($body = '', $properties = null)
    {
        $this->body = $body;
        parent::__construct($properties, $this->_basePropTypes);
    }
}
