<?php

//require_once 'amqp.php';
//require_once 'rabbit.php';
use amqp_091 as amqp;
use amqp_091\protocol;
use amqp_091\wire;

require('amqp.php');




class DebugConsumer extends amqp\SimpleConsumer
{
    private $i = 0;
    private $myChan;
    private $myName;
    private $cancelled = false;

    function __construct (wire\Method $consume = null, $myName) {
        parent::__construct($consume);
        $this->myName = $myName;
    }

    function handleCancelOk (wire\Method $meth) {
        parent::handleCancelOk($meth);
        printf("Received Cancel-OK\n");
        $this->cancelled = true;
    }


    function handleDelivery (wire\Method $meth) {
        /*printf("(%s, %s.%s, %s):\n  %s\n",
               $this->myName,
               $meth->getClassProto()->getSpecName(),
               $meth->getMethodProto()->getSpecName(),
               $meth->getField('delivery-tag'),
               $meth->getContent());*/
        ++$this->i;
        if ($this->cancelled || (($this->i % 3) == 0)) {
            // reject every third message
            //printf(" [reject message %d]\n", $meth->getField('delivery-tag'));
            $resp = new wire\Method(protocol\ClassFactory::GetMethod('basic', 'reject'), $meth->getWireChannel());
            $resp->setField('delivery-tag', $meth->getField('delivery-tag'));
            $resp->setField('requeue', true);
            echo "r";
            return $resp;
        } else if (($this->i % 50) == 0) {
            printf("+BC+");
            $resp = new wire\Method(protocol\ClassFactory::GetMethod('basic', 'cancel'), $meth->getWireChannel());
            $resp->setField('consumer-tag', $meth->getField('consumer-tag'));
            $resp->setField('no-wait', false);
            $this->cancelled = true;
            return $resp;
        } else {
            $resp = new wire\Method(protocol\ClassFactory::GetMethod('basic', 'ack'), $meth->getWireChannel());
            $resp->setField('delivery-tag', $meth->getField('delivery-tag'));
            $resp->setField('multiple', false);
            echo "a";
            return $resp;
        }
    }
}



// Script starts


$VH_NAME = 'robin';
$EX = 'router3';
$EX_TYPE = 'fanout';
$Q = 'newq-2';
$USER = 'testing';
$PASS = 'letmein';
$HOST = 'localhost';
$PORT = 5672;


//deleteExchange();
//die("again..\n");

if (isset($argv[1]) && strtolower($argv[1]) == 'consume') {
    doConsume();
} else {
    $n = (isset($argv[1]) && is_numeric($argv[1])) ?
        (int) $argv[1]
        : 2;
    doProduce ($n);
}
// Script ends


//
// NEW TESTS
//





// This one PRODUCES, then consumes all remaining messages
function doProduce ($n) {
    global $VH_NAME;
    global $EX_TYPE;
    global $EX;
    global $Q;

    // Produce
    $conn = getConnection();
    $chan = $conn->getChannel();


    // Start a transaction
    //$chan->invoke($chan->tx('select'));


    // Pushes content to a queue
    $basicP = $chan->basic('publish', array('content-type' => 'text/plain',
                                            'content-encoding' => 'UTF-8',
                                            'routing-key' => '',
                                            'mandatory' => false,
                                            'immediate' => false,
                                            'exchange' => $EX));

    for ($i = 0; $i < $n; $i++) {
        $basicP->setContent(sprintf("You should help out the aged beatnik %d times!", $i + 1));
        $chan->invoke($basicP);
    }

    echo "Written $i messages to the broker\n";
    $conn->shutdown();
    return; // ***************


    // Pull a single message from the queue
    $basicGet = $chan->basic('get', array('queue' => $Q));
    // Suck all content from that Q.
    $contents = array();
    $i = $delTag = 0;
    while (true) {
        $getOk = $chan->invoke($basicGet);
        //printf(" [%d bytes read]\n", $getOk->getBytesRead());
        if ($i == 1) {
            printf("GetOK Class fields:\n");
            foreach ($getOk->getClassFields() as $k => $v) {
                if (is_bool($v)) {
                    $v = $v ? 'true' : 'false';
                }
                printf(" %s => %s\n", $k, $v);
            }
            printf("GetOK Method fields:\n");
            foreach ($getOk->getFields() as $k => $v) {
                if (is_bool($v)) {
                    $v = $v ? 'true' : 'false';
                }
                printf(" %s => %s\n", $k, $v);
            }
        }
        if ($getOk->getMethodProto()->getSpecHasContent()) {
            $c = $getOk->getContent();
            if (! in_array($c, $contents)) {
                $contents[] = $c;
            }
            $delTag = $getOk->getField('delivery-tag');
        } else {
            break;
        }
        if (($i++ % 100) == 0) {
            printf("...Consumed %d messages\n", $i);
        }
    }

    printf("Read %d messages, distinct versions are:\n%s", $i, implode("\n", $contents));
    //printf("Get result method %s, content:\n%s\n", $getOk->getClassProto()->getSpecName(), $getOk->getContent());

    // Send an ack to clear all msgs
    $basicAck = $chan->basic('ack', array('delivery-tag' => $delTag, 'multiple' => true));
    $chan->invoke($basicAck);

    //$chan->invoke($chan->tx('commit'));


    $conn->shutdown();

}



function doConsume () {
    // Consume
    global $VH_NAME;
    global $EX_TYPE;
    global $EX;
    global $Q;

    $conn = getConnection();
    $chan = $conn->getChannel();


    // Declare the exchange

    $excDecl = $chan->exchange('declare', array('type' => $EX_TYPE,
                                                'durable' => true,
                                                'exchange' => $EX));
    $chan->invoke($excDecl);

    // Declare the queue
    $qDecl = $chan->queue('declare', array('queue' => $Q));
    $chan->invoke($qDecl);

    // Bind Q to EX
    $qBind = $chan->queue('bind', array('queue' => $Q,
                                        'routing-key' => '',
                                        'exchange' => $EX));
    $chan->invoke($qBind);

    $shutdown = function () use ($conn) { echo "\nDo channel shutdown\n"; $conn->shutdown(); die; };
    pcntl_signal(SIGINT, $shutdown); 
    pcntl_signal(SIGTERM, $shutdown);
    //register_shutdown_function($shutdown);


    $cons1 = $chan->basic('consume', array('queue' => $Q,
                                           'no-local' => true,
                                           'no-ack' => false,
                                           'exclusive' => false,
                                           'no-wait' => false));
    $chan->addConsumer(new DebugConsumer($cons1, '{1}'));

    // Create a second channel to receive messages on
    /*$chan2 = $conn->getChannel();
    $cons2 = $chan2->basic('consume', array('queue' => $Q,
                                           'no-local' => true,
                                           'no-ack' => false,
                                           'exclusive' => false,
                                           'no-wait' => false));*/
    $chan->addConsumer(new DebugConsumer($cons1, '{2}'));

    // Consume messages forever, blocks indefinitely
    try {
        $conn->startConsuming();
    } catch (Exception $e) {
        printf("Exception in consume loop:\n{$e->getMessage()}\n");
    }
    $conn->shutdown();
}











/** Return an XML serialized version of meth  */
function methodToXml (wire\Method $meth) {
    $w = new XmlWriter;
    $w->openMemory();
    $w->setIndent(true);
    $w->setIndentString('  ');
    $w->startElement('msg');
    $w->writeAttribute('class', $meth->getClassProto()->getSpecName());
    $w->writeAttribute('method', $meth->getMethodProto()->getSpecName());
    $w->writeAttribute('channel', $meth->getWireChannel());
    $w->startElement('class-fields');
    if ($meth->getClassFields()) {
        foreach ($meth->getClassFields() as $fn => $fv) {
            $w->startElement('field');
            $w->writeAttribute('name', $fn);
            if (is_bool($fv)) {
                $w->text('(false)');
            } else {
                $w->text($fv);
            }
            $w->endElement(); // field
        }
    }
    $w->endElement(); // class-fields


    $w->startElement('method-fields');
    if ($meth->getFields()) {
        foreach ($meth->getFields() as $fn => $fv) {
            $w->startElement('field');
            $w->writeAttribute('name', $fn);
            if (is_bool($fv)) {
                $w->text('(false)');
            } else {
                $w->text($fv);
            }
            $w->endElement(); // field
        }
    }
    $w->endElement(); // method-fields
    $w->startElement('content');
    $w->text($meth->getContent());
    $w->endElement(); // content
    $w->endElement(); // msg
    return $w->flush();
}

/** Delete the exchange $EX */
function deleteExchange () {
    global $EX;
    $conn = getConnection();
    $chan = $conn->getChannel();

    $meth = $chan->exchange('delete', array('exchange' => $EX,
                                            'if-unused' => false));
    $chan->invoke($meth);
}


/** Return a connection from the global config */
function getConnection () {
    global $HOST;
    global $PORT;
    global $VH_NAME;
    global $USER;
    global $PASS;

    $sParams = array('host' => $HOST,
                     'port' => $PORT,
                     'username' => $USER,
                     'userpass' => $PASS,
                     'vhost' => $VH_NAME);
    $connFact = new amqp\ConnectionFactory($sParams);
    return $conn = $connFact->newConnection();
}

// Pretty print the given backtrace, from debug_backtrace, return as string
function printBacktrace ($bt) {
    $r = '';
    foreach ($bt as $t) {
        if (isset($t['type']) && ($t['type'] == '->' || $t['type'] == '::')) {
            $r .= sprintf("Class call %s%s%s at %s [%s]\n", $t['class'], $t['type'], $t['function'], basename($t['file']), $t['line']);
        } else if (isset($t['function'])) {
            $r .= sprintf("Function call %s at %s [%s]\n", $t['function'], basename($t['file']), $t['line']);
        } else {
            $r .= sprintf("File %s [%s]\n", basename($t['file']), $t['line']);
        }
    }
    return $r;
}