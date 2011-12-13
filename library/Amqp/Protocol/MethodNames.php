<?php

namespace Amqp\Protocol;
use Amqp\Exception;

class MethodNames
{
	const METHOD_CONNECTION_START = "10,10";
	const METHOD_CONNECTION_START_OK = "10,11";
	const METHOD_CONNECTION_SECURE = "10,20";
	const METHOD_CONNECTION_SECURE_OK = "10,21";
	const METHOD_CONNECTION_TUNE = "10,30";
	const METHOD_CONNECTION_TUNE_OK = "10,31";
	const METHOD_CONNECTION_OPEN = "10,40";
	const METHOD_CONNECTION_OPEN_OK = "10,41";
	const METHOD_CONNECTION_REDIRECT = "10,50";
	const METHOD_CONNECTION_CLOSE = "10,60";
	const METHOD_CONNECTION_CLOSE_OK = "10,61";
	const METHOD_CHANNEL_OPEN = "20,10";
	const METHOD_CHANNEL_OPEN_OK = "20,11";
	const METHOD_CHANNEL_FLOW = "20,20";
	const METHOD_CHANNEL_FLOW_OK = "20,21";
	const METHOD_CHANNEL_ALERT = "20,30";
	const METHOD_CHANNEL_CLOSE = "20,40";
	const METHOD_CHANNEL_CLOSE_OK = "20,41";
	const METHOD_CHANNEL_ACCESS_REQUEST = "30,10";
	const METHOD_CHANNEL_ACCESS_REQUEST_OK = "30,11";
	const METHOD_CHANNEL_EXCHANGE_DECLARE = "40,10";
	const METHOD_CHANNEL_EXCHANGE_DECLARE_OK = "40,11";
	const METHOD_CHANNEL_EXCHANGE_DELETE = "40,20";
	const METHOD_CHANNEL_EXCHANGE_DELETE_OK = "40,21";
	const METHOD_CHANNEL_QUEUE_DECLARE = "50,10";
	const METHOD_CHANNEL_QUEUE_DECLARE_OK = "50,11";
	const METHOD_CHANNEL_QUEUE_BIND = "50,20";
	const METHOD_CHANNEL_QUEUE_BIND_OK = "50,21";
	const METHOD_CHANNEL_QUEUE_PURGE = "50,30";
	const METHOD_CHANNEL_QUEUE_PURGE_OK = "50,31";
	const METHOD_CHANNEL_QUEUE_DELETE = "50,40";
	const METHOD_CHANNEL_QUEUE_DELETE_OK = "50,41";
	const METHOD_CHANNEL_BASIC_QOS = "60,10";
	const METHOD_CHANNEL_BASIC_QOS_OK = "60,11";
	const METHOD_CHANNEL_BASIC_CONSUME = "60,20";
	const METHOD_CHANNEL_BASIC_CONSUME_OK = "60,21";
	const METHOD_CHANNEL_BASIC_CANCEL = "60,30";
	const METHOD_CHANNEL_BASIC_CANCEL_OK = "60,31";
	const METHOD_CHANNEL_BASIC_PUBLISH = "60,40";
	const METHOD_CHANNEL_BASIC_RETURN = "60,50";
	const METHOD_CHANNEL_BASIC_DELIVER = "60,60";
	const METHOD_CHANNEL_BASIC_GET = "60,70";
	const METHOD_CHANNEL_BASIC_GET_OK = "60,71";
	const METHOD_CHANNEL_BASIC_GET_EMPTY = "60,72";
	const METHOD_CHANNEL_BASIC_ACK = "60,80";
	const METHOD_CHANNEL_BASIC_REJECT = "60,90";
	const METHOD_CHANNEL_BASIC_RECOVER = "60,100";
	const METHOD_CHANNEL_TX_SELECT = "90,10";
	const METHOD_CHANNEL_TX_SELECT_OK = "90,11";
	const METHOD_CHANNEL_TX_COMMIT = "90,20";
	const METHOD_CHANNEL_TX_COMMIT_OK = "90,21";
	const METHOD_CHANNEL_TX_ROLLBACK = "90,30";
	const METHOD_CHANNEL_TX_ROLLBACK_OK = "90,31";
	
	private static $_methodNamesMap = array(
		"10,10" => "Connection.start",
		"10,11" => "Connection.start_ok",
		"10,20" => "Connection.secure",	
	    "10,21" => "Connectiophp -d include_path='library/php-amqplib'n.secure_ok",
	    "10,30" => "Connection.tune",
	    "10,31" => "Connection.tune_ok",
	    "10,40" => "Connection.open",
	    "10,41" => "Connection.open_ok",
	    "10,50" => "Connection.redirect",
	    "10,60" => "Connection.close",
	    "10,61" => "Connection.close_ok",
	    "20,10" => "Channel.open",
	    "20,11" => "Channel.open_ok",
	    "20,20" => "Channel.flow",
	    "20,21" => "Channel.flow_ok",
	    "20,30" => "Channel.alert",
	    "20,40" => "Channel.close",
	    "20,41" => "Channel.close_ok",
	    "30,10" => "Channel.access_request",
	    "30,11" => "Channel.access_request_ok",
	    "40,10" => "Channel.exchange_declare",
	    "40,11" => "Channel.exchange_declare_ok",
	    "40,20" => "Channel.exchange_delete",
	    "40,21" => "Channel.exchange_delete_ok",
	    "50,10" => "Channel.queue_declare",
	    "50,11" => "Channel.queue_declare_ok",
	    "50,20" => "Channel.queue_bind",
	    "50,21" => "Channel.queue_bind_ok",
	    "50,30" => "Channel.queue_purge",
	    "50,31" => "Channel.queue_purge_ok",
	    "50,40" => "Channel.queue_delete",
	    "50,41" => "Channel.queue_delete_ok",
	    "60,10" => "Channel.basic_qos",
	    "60,11" => "Channel.basic_qos_ok",
	    "60,20" => "Channel.basic_consume",
	    "60,21" => "Channel.basic_consume_ok",
	    "60,30" => "Channel.basic_cancel",
	    "60,31" => "Channel.basic_cancel_ok",
	    "60,40" => "Channel.basic_publish",
	    "60,50" => "Channel.basic_return",
	    "60,60" => "Channel.basic_deliver",
	    "60,70" => "Channel.basic_get",
	    "60,71" => "Channel.basic_get_ok",
	    "60,72" => "Channel.basic_get_empty",
	    "60,80" => "Channel.basic_ack",
	    "60,90" => "Channel.basic_reject",
	    "60,100" => "Channel.basic_recover",
	    "90,10" => "Channel.tx_select",
	    "90,11" => "Channel.tx_select_ok",
	    "90,20" => "Channel.tx_commit",
	    "90,21" => "Channel.tx_commit_ok",
	    "90,30" => "Channel.tx_rollback",
	    "90,31" => "Channel.tx_rollback_ok"
	);
	
	static public function map($methodSig)
	{
		if (is_string($methodSig)) {
			$name = $methodSig;
		} else if (is_array($methodSig)) {
			$name = sprintf("%d,%d", $methodSig[0], $methodSig[1]);
		} else {
			return null;
		}
		
		return self::$_methodNamesMap[$name];
	}
}
