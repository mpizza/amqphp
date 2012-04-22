<?php
 namespace amqphp\persistent; class PChannel extends \amqphp\Channel implements \Serializable { public $suspendOnSerialize = false; public $resumeOnHydrate = false; private static $PersProps = array('chanId', 'flow', 'frameMax', 'confirmSeqs', 'confirmSeq', 'confirmMode', 'isOpen', 'callbackHandler', 'suspendOnSerialize', 'resumeOnHydrate', 'ackBuffer', 'ackHead', 'numPendAcks', 'ackFlag'); function serialize () { $data = array(); foreach (self::$PersProps as $k) { $data[$k] = $this->$k; } $data['consumers'] = array(); foreach ($this->consumers as $cons) { if ($cons[0] instanceof \Serializable && $cons[2] == 'READY') { $data['consumers'][] = $cons; } } return serialize($data); } function unserialize ($data) { $data = unserialize($data); foreach (self::$PersProps as $p) { $this->$p = $data[$p]; } foreach ($data['consumers'] as $i => $c) { $this->consumers[$i] = array($c[0], $c[1], $c[2], $c[3]); } } }