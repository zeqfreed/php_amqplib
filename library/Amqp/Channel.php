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
use Amqp\Wire\Writer;
use Amqp\AbstractChannel;

class Channel extends AbstractChannel
{
    protected $_methodMap = array(
        MethodNames::METHOD_CHANNEL_OPEN_OK => "openOk",
        MethodNames::METHOD_CHANNEL_FLOW => "flow",
        MethodNames::METHOD_CHANNEL_FLOW_OK => "flowOk",
        MethodNames::METHOD_CHANNEL_ALERT => "alert",
        MethodNames::METHOD_CHANNEL_CLOSE => "channelClose",
        MethodNames::METHOD_CHANNEL_CLOSE_OK => "closeOk",
        MethodNames::METHOD_CHANNEL_ACCESS_REQUEST_OK => "accessRequestOk",
        MethodNames::METHOD_CHANNEL_EXCHANGE_DECLARE_OK => "exchangeDeclareOk",
        MethodNames::METHOD_CHANNEL_EXCHANGE_DELETE_OK => "exchangeDeleteOk",
        MethodNames::METHOD_CHANNEL_QUEUE_DECLARE_OK => "queueDeclareOk",
        MethodNames::METHOD_CHANNEL_QUEUE_BIND_OK => "queueBindOk",
        MethodNames::METHOD_CHANNEL_QUEUE_PURGE_OK => "queuePurgeOk",
        MethodNames::METHOD_CHANNEL_QUEUE_DELETE_OK => "queueDeleteOk",
        MethodNames::METHOD_CHANNEL_BASIC_QOS_OK => "basicQosOk",
        MethodNames::METHOD_CHANNEL_BASIC_CONSUME_OK => "basicConsumeOk",
        MethodNames::METHOD_CHANNEL_BASIC_CANCEL_OK => "basicCancel_ok",
        MethodNames::METHOD_CHANNEL_BASIC_RETURN => "basicReturn",
        MethodNames::METHOD_CHANNEL_BASIC_DELIVER => "basicDeliver",
        MethodNames::METHOD_CHANNEL_BASIC_GET_OK => "basicGetOk",
        MethodNames::METHOD_CHANNEL_BASIC_GET_EMPTY => "basicGetEmpty",
        MethodNames::METHOD_CHANNEL_TX_SELECT_OK => "txSelectOk",
        MethodNames::METHOD_CHANNEL_TX_COMMIT_OK => "txCommitOk",
        MethodNames::METHOD_CHANNEL_TX_ROLLBACK_OK => "txRollbackOk",
    );
    
    private $_defaultTicket = 0;
    private $_isOpen = false;
    private $_active = true;
    private $_alerts = array();
    private $_callbacks = array();
    protected $_autoDecode = true;
    
    public function __construct($connection, $channelId = null, $autoDecode = true)
    {
        if (null == $channelId) {
            $channelId = $connection->getFreeChannelId();
        }

        parent::__construct($connection, $channelId);
        $this->xOpen();
    }

    /**
     * Tear down this object, after we've agreed to close with the server.
     */
    protected function doClose()
    {
        $this->_isOpen = false;
        unset($this->_connection->_channels[$this->_channelId]);
        $this->_channelId = null;
        $this->_connection = null;
    }

    /**
     * This method allows the server to send a non-fatal warning to
     * the client.  This is used for methods that are normally
     * asynchronous and thus do not have confirmations, and for which
     * the server may detect errors that need to be reported.  Fatal
     * errors are handled as channel or connection exceptions; non-
     * fatal errors are sent through this method.
     */
    protected function alert($args)
    {
        $replyCode = $args->readShort();
        $replyText = $args->readShortStr();
        $details = $args->readTable();

        array_push($this->_alerts, array($replyCode, $replyText, $details));
    }

    /**
     * request a channel close
     */
    public function close($replyCode = 0, $replyText = '', $methodSig = array(0, 0))
    {
        $args = new Writer();
        $args->writeShort($replyCode);
        $args->writeShortStr($replyText);
        $args->writeShort($methodSig[0]); // class_id
        $args->writeShort($methodSig[1]); // method_id
        $this->sendMethodFrame(array(20, 40), $args);
        
        $result = $this->wait(array(
            MethodNames::METHOD_CHANNEL_CLOSE_OK,
        ));
        
        return $result;
    }


    protected function channelClose($args)
    {
        $replyCode = $args->readShort();
        $replyText = $args->readShortStr();
        $classId   = $args->readShort();
        $methodId  = $args->readShort();

        $this->sendMethodFrame(array(20, 41));
        $this->doClose();
        
        throw new ChannelException($replyCode, $replyText, array($classId, $methodId));
    }
    
    /**
     * confirm a channel close
     */
    protected function closeOk($args)
    {
        $this->doClose();
    }

    /**
     * enable/disable flow from peer
     */
    public function flow($active)
    {
        $args = new Writer();
        $args->writeBit($active);
        $this->sendMethodFrame(array(20, 20), $args);
        
        $result = $this->wait(array(
            MethodNames::METHOD_CHANNEL_FLOW_OK,
        ));
        
        return $result;
    }

//     protected function _flow($args)
//     {
//         $this->active = $args->readBit();
//         $this->xFlowOk($this->_active);
//     }

//     protected function x_flow_ok($active)
//     {
//         $args = new Writer();
//         $args->writeBit($active);
//         $this->sendMethodFrame(array(20, 21), $args);
//     }

    protected function flowOk($args)
    {
        return $args->readBit();
    }
    
    protected function xOpen($outOfBand = '')
    {
        if ($this->_isOpen) {
            return;
        }
        
        $args = new Writer();
        $args->writeShortStr($outOfBand);
        $this->sendMethodFrame(array(20, 10), $args);
        
        $result = $this->wait(array(
            MethodNames::METHOD_CHANNEL_OPEN_OK,
        ));
        
        return $result;
    }
    
    protected function openOk($args)
    {
        $this->_isOpen = true;
    }

    /**
     * request an access ticket
     */
    public function accessRequest($realm, $exclusive = false, $passive = false,
                                   $active = false, $write = false, $read = false)
    {
        $args = new Writer();
        $args->writeShortStr($realm);
        $args->writeBit($exclusive);
        $args->writeBit($passive);
        $args->writeBit($active);
        $args->writeBit($write);
        $args->writeBit($read);
        $this->sendMethodFrame(array(30, 10), $args);
        
        $result = $this->wait(array(
            MethodNames::METHOD_CHANNEL_ACCESS_REQUEST_OK,
        ));
        
        return $result;
    }

    /**
     * grant access to server resources
     */
    protected function accessRequestOk($args)
    {
        $this->_defaultTicket = $args->readShort();
        return $this->_defaultTicket;
    }
        

    /**
     * declare exchange, create if needed
     */
    public function exchangeDeclare($exchange, $type, $passive = false, $durable = false,
                                    $autoDelete = true, $internal = false, $nowait = false,
                                    $arguments = null, $ticket = null)
    {
        if (null == $arguments) {
            $arguments = array();
        }
        
        $args = new Writer();
        if(null !== $ticket) {
            $args->writeShort($ticket);
        } else {
            $args->write_short($this->_defaultTicket);
        }
        
        $args->writeShortStr($exchange);
        $args->writeShortStr($type);
        $args->writeBit($passive);
        $args->writeBit($durable);
        $args->writeBit($autoDelete);
        $args->writeBit($internal);
        $args->writeBit($nowait);
        $args->writeTable($arguments);
        $this->sendMethodFrame(array(40, 10), $args);

        if (!$nowait) {
            $result = $this->wait(array(
                MethodNames::METHOD_CHANNEL_EXCHANGE_DECLARE_OK,
            ));
            
            return $result;
        }
    }

    /**
     * confirms an exchange declaration
     */
    protected function exchangeDeclareOk($args)
    {
        //
    }

    /**
     * delete an exchange
     */
    public function exchangeDelete($exchange, $ifUnused = false, $nowait = false, $ticket = null)
    {
        $args = new AMQPWriter();
        if (null !== $ticket) {
            $args->writeShort($ticket);
        } else {
            $args->writeShort($this->_defaultTicket);
        }
        
        $args->writeShortstr($exchange);
        $args->writeBit($ifUnused);
        $args->writeBit($nowait);
        $this->sendMethodFrame(array(40, 20), $args);

        if (!$nowait) {
            $result = $this->wait(array(
                MethodNames::METHOD_CHANNEL_EXCHANGE_DELETE_OK,
            ));
            
            return $result;
        }
    }

    /**
     * confirm deletion of an exchange
     */
    protected function exchangeDeleteOk($args)
    {
        //
    }


    /**
     * bind queue to an exchange
     */
    public function queueBind($queue, $exchange, $routingKey = '', $nowait = false, $arguments = null, $ticket = null)
    {
        if (null == $arguments) {
            $arguments = array();
        }

        $args = new Writer();
        if (null !== $ticket) {
            $args->writeShort($ticket);
        } else {
            $args->writeShort($this->_defaultTicket);
        }
        $args->writeShortStr($queue);
        $args->writeShortStr($exchange);
        $args->writeShortStr($routingKey);
        $args->writeBit($nowait);
        $args->writeTable($arguments);
        $this->sendMethodFrame(array(50, 20), $args);

        if (!$nowait) {
            $result = $this->wait(array(
                MethodNames::METHOD_CHANNEL_QUEUE_BIND_OK,
            ));
            
            return $result;
        }
    }

    /**
     * confirm bind successful
     */
    protected function queueBindOk($args)
    {
        //
    }

    /**
     * declare queue, create if needed
     */
    public function queueDeclare($queue = '', $passive = false, $durable = false, $exclusive = false,
                                 $autoDelete = true, $nowait = false, $arguments = null, $ticket = null)
    {
        if (null == $arguments) {
            $arguments = array();
        }

        $args = new Writer();
        if(null !== $ticket) {
            $args->writeShort($ticket);
        } else {
            $args->writeShort($this->_defaultTicket);
        }
        
        $args->writeShortStr($queue);
        $args->writeBit($passive);
        $args->writeBit($durable);
        $args->writeBit($exclusive);
        $args->writeBit($autoDelete);
        $args->writeBit($nowait);
        $args->writeTable($arguments);
        $this->sendMethodFrame(array(50, 10), $args);

        if (!$nowait) {
            $result = $this->wait(array(
                MethodNames::METHOD_CHANNEL_QUEUE_DECLARE_OK,
            ));
            
            return $result;
        }
    }        

    /**
     * confirms a queue definition
     */
    protected function queueDeclareOk($args)
    {
        $queue = $args->readShortStr();
        $messageCount = $args->readLong();
        $consumerCount = $args->readLong();
        
        return array($queue, $messageCount, $consumerCount);
    }

    /**
     * delete a queue
     */
    public function queueDelete($queue = '', $ifUnused = false, $ifEmpty = false, $nowait = false, $ticket = null)
    {
        $args = new Writer();
        
        if(null !== $ticket) {
            $args->writeShort($ticket);
        } else {
            $args->writeShort($this->_defaultTicket);
        }

        $args->writeShortstr($queue);
        $args->writeBit($ifUnused);
        $args->writeBit($ifEmpty);
        $args->writeBit($nowait);
        $this->sendMethodFrame(array(50, 40), $args);
        
        if (!$nowait) {
            $result = $this->wait(array(
                MethodNames::METHOD_CHANNEL_QUEUE_DELETE_OK,
            ));
        
            return $result;
        }
    }

    /**
     * confirm deletion of a queue
     */
    protected function queueDeleteOk($args)
    {
        return $args->readLong();
    }

    /**
     * purge a queue
     */
    public function queuePurge($queue = '', $nowait = false, $ticket = null)
    {
        $args = new Writer();
        
        if(null !== $ticket) {
            $args->writeShort($ticket);
        } else {
            $args->writeShort($this->_defaultTicket);
        }
        
        $args->writeShortstr($queue);
        $args->writeBit($nowait);
        $this->sendMethodFrame(array(50, 30), $args);
        
        if (!$nowait) {
            $result = $this->wait(array(
                MethodNames::METHOD_CHANNEL_QUEUE_PURGE_OK,
            ));
        
            return $result;
        }
    }

    /**
     * confirms a queue purge
     */
    protected function queuePurgeOk($args)
    {
        return $args->readLong();
    }

    /**
     * acknowledge one or more messages
     */
    public function basicAck($deliveryTag, $multiple = false)
    {
        $args = new Writer();
        $args->writeLongLong($deliveryTag);
        $args->writeBit($multiple);
        $this->sendMethodFrame(array(60, 80), $args);
    }

    /**
     * end a queue consumer
     */
    public function basicCancel($consumerTag, $nowait = false)
    {
        $args = new Writer();
        $args->writeShortstr($consumerTag);
        $args->writeBit($nowait);
        $this->sendMethodFrame(array(60, 30), $args);
        
        if (!$nowait) {
            $result = $this->wait(array(
                MethodNames::METHOD_CHANNEL_BASIC_CANCEL_OK,
            ));
        
            return $result;
        }        
    }

    /**
     * confirm a cancelled consumer
     */
    protected function basicCancelOk($args)
    {
        $consumerTag = $args->readShortStr();
        unset($this->_callbacks[$consumerTag]);
    }

    /**
     * start a queue consumer
     */
    public function basicConsume($queue = '', $consumerTag = '', $noLocal = false,
                                 $noAck = false, $exclusive = false, $nowait = false,
                                 $callback = null, $ticket = null)
    {
        $args = new Writer();
        
        if(null !== $ticket) {
            $args->writeShort($ticket);
        } else {
            $args->writeShort($this->_defaultTicket);
        }
        
        $args->writeShortStr($queue);
        $args->writeShortStr($consumerTag);
        $args->writeBit($noLocal);
        $args->writeBit($noAck);
        $args->writeBit($exclusive);
        $args->writeBit($nowait);
        $this->sendMethodFrame(array(60, 20), $args);
        
        if (!$nowait) {
            $consumerTag = $this->wait(array(
                MethodNames::METHOD_CHANNEL_BASIC_CONSUME_OK,
            ));
        }
            
        $this->_callbacks[$consumerTag] = $callback;
        return $consumerTag;        
    }

    /**
     * confirm a new consumer
     */
    protected function basicConsumeOk($args)
    {
        return $args->readShortStr();
    }

    /**
     * notify the client of a consumer message
     */
    protected function basicDeliver($args, $msg)
    {
        $consumerTag = $args->readShortStr();
        $deliveryTag = $args->readLongLong();
        $redelivered = $args->readBit();
        $exchange = $args->readShortStr();
        $routingKey = $args->readShortStr();
        
        $msg->deliveryInfo = array(
            "channel" => $this,
            "consumer_tag" => $consumerTag,
            "delivery_tag" => $deliveryTag,
            "redelivered" => $redelivered,
            "exchange" => $exchange,
            "routing_key" => $routingKey
        );

        if (array_key_exists($consumerTag, $this->_callbacks)) {
            $func = $this->_callbacks[$consumerTag];
        } else {
            $func = null;
        }
        
        if (null !== $func) {
            call_user_func($func, $msg);
        }
    }

    /**
     * direct access to a queue
     */
    public function basicGet($queue = '', $noAck = false, $ticket = null)
    {
        $args = new Writer();
        
        if(null !== $ticket) {
            $args->writeShort($ticket);
        } else {
            $args->writeShort($this->_defaultTicket);
        }
        
        $args->writeShortStr($queue);
        $args->writeBit($noAck);
        $this->sendMethodFrame(array(60, 70), $args);
        $result = $this->wait(array(
            MethodNames::METHOD_CHANNEL_BASIC_GET_OK,
            MethodNames::METHOD_CHANNEL_BASIC_GET_EMPTY,
        ));
        
        return $result;
    }

    /**
     * indicate no messages available
     */
    protected function basicGetEmpty($args)
    {
        $clusterId = $args->readShortStr();
    }

    /**
     * provide client with a message
     */
    protected function basicGetOk($args, $msg)
    {
        $deliveryTag = $args->readLongLong();
        $redelivered = $args->readBit();
        $exchange = $args->readShortStr();
        $routingKey = $args->readShortStr();
        $messageCount = $args->readLong();

        $msg->deliveryInfo = array(
            "delivery_tag" => $deliveryTag,
            "redelivered" => $redelivered,
            "exchange" => $exchange,
            "routing_key" => $routingKey,
            "message_count" => $messageCount
        );
        
        return $msg;
    }

    /**
     * publish a message
     */
    public function basicPublish($msg, $exchange = '', $routingKey = '', $mandatory = false, $immediate = false, $ticket = null)
    {
        $args = new Writer();
        
        if(null !== $ticket) {
            $args->writeShort($ticket);
        } else {
            $args->writeShort($this->_defaultTicket);
        }
        
        $args->writeShortStr($exchange);
        $args->writeShortStr($routingKey);
        $args->writeBit($mandatory);
        $args->writeBit($immediate);
        $this->sendMethodFrame(array(60, 40), $args);
        
        $this->_connection->sendContent($this->_channelId, 60, 0, strlen($msg->body), $msg->serializeProperties(), $msg->body);
    }   

    /**
     * specify quality of service
     */
    public function basicQos($prefetchSize, $prefetchCount, $aGlobal)
    {
        $args = new Writer();
        $args->writeLong($prefetchSize);
        $args->writeShort($prefetchCount);
        $args->writeBit($aGlobal);
        $this->sendMethodFrame(array(60, 10), $args);
        $result = $this->wait(array(
            MethodNames::METHOD_CHANNEL_BASIC_QOS_OK,
        ));
        
        return $result;
    }


    /**
     * confirm the requested qos
     */
    protected function basicQosOk($args)
    {
    }

    /**
     * redeliver unacknowledged messages
     */
    public function basicRecover($requeue = false)
    {
        $args = new Writer();
        $args->writeBit($requeue);
        $this->sendMethodFrame(array(60, 100), $args);
    }

    /**
     * reject an incoming message
     */
    public function basicReject($deliveryTag, $requeue)
    {
        $args = new Writer();
        $args->writeLongLong($deliveryTag);
        $args->writeBit($requeue);
        $this->sendMethodFrame(array(60, 90), $args);
    }

    /**
     * return a failed message
     */
    protected function basicReturn($args)
    {
        $replyCode = $args->readShort();
        $replyText = $args->readShortStr();
        $exchange = $args->readShortStr();
        $routingKey = $args->readShortStr();
        $msg = $this->wait();
    }


    public function txCommit()
    {
        $this->sendMethodFrame(array(90, 20));
        
        $result = $this->wait(array(
            MethodNames::METHOD_CHANNEL_TX_COMMIT_OK,
        ));
        
        return $result;
    }
    
    /**
     * confirm a successful commit
     */
    protected function txCommitOk($args)
    {
    }
    
    /**
     * abandon the current transaction
     */
    public function txRollback()
    {
        $this->sendMethodFrame(array(90, 30));
        
        $result = $this->wait(array(
            MethodNames::METHOD_CHANNEL_TX_ROLLBACK_OK,
        ));
        
        return $result;
    }

    /**
     * confirm a successful rollback
     */
    protected function txRollbackOk($args)
    {
    }

    /**
     * select standard transaction mode
     */
    public function txSelect()
    {
        $this->sendMethodFrame(array(90, 10));
        
        $result = $this->wait(array(
            MethodNames::METHOD_CHANNEL_TX_SELECT_OK,
        ));
        
        return $result;
    }

    /**
     * confirm transaction mode
     */
    protected function txSelectOk($args)
    {
    }
}
