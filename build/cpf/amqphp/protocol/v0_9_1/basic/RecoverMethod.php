<?php
namespace amqphp\protocol\v0_9_1\basic; class RecoverMethod extends \amqphp\protocol\abstrakt\XmlSpecMethod { protected $class = 'basic'; protected $name = 'recover'; protected $index = 110; protected $synchronous = false; protected $responseMethods = array(); protected $fields = array('requeue'); protected $methFact = '\\amqphp\\protocol\\v0_9_1\\basic\\MethodFactory'; protected $fieldFact = '\\amqphp\\protocol\\v0_9_1\\basic\\FieldFactory'; protected $classFact = '\\amqphp\\protocol\\v0_9_1\\ClassFactory'; protected $content = false; protected $hasNoWait = false; }