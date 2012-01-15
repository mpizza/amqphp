<?php
namespace amqphp\wire;
 final class Hexdump { final static function hexdump ($buff) { static $f, $f2; if ($buff === '') { return "00000000\n"; } if (is_null($f)) { $f = function ($char) { return sprintf('%02s', dechex(ord($char))); }; $f2 = function ($char) { $ord = ord($char); return ($ord > 31 && $ord < 127) ? chr($ord) : '.'; }; } $l = strlen($buff); $ret = ''; for ($i = 0; $i < $l; $i += 16) { $line = substr($buff, $i, 8); $ll = $offLen = strlen($line); $rem = (8 - $ll) * 3; $hexes = vsprintf(str_repeat('%3s', $ll), array_map($f, str_split($line, 1))); $chars = '|' . vsprintf(str_repeat('%s', $ll), array_map($f2, str_split($line, 1))); $lBuff = sprintf("%08s %s", dechex($i), $hexes); if ($line = substr($buff, $i + 8, 8)) { $ll = strlen($line); $offLen += $ll; $rem = (8 - $ll) * 3 + 1; $hexes = vsprintf(str_repeat('%3s', $ll), array_map($f, str_split($line, 1))); $chars .= ' '. vsprintf(str_repeat('%s', $ll), array_map($f2, str_split($line, 1))); $lBuff .= sprintf(" %s%{$rem}s %s|\n", $hexes, ' ', $chars); } else { $lBuff .= ' ' . str_repeat(" ", $rem + 26) . $chars . "|\n"; } $ret .= $lBuff; } return sprintf("%s%08s\n", $ret, dechex($l)); } }
 use amqphp\protocol as proto, amqphp\protocol\abstrakt;  class Writer extends Protocol { private $binPackOffset = 0; function write ($value, $type, $tableField=false) { $implType = ($tableField) ? $this->getImplForTableType($type) : $this->getImplForXmlType($type); if (! $implType) { trigger_error(sprintf("Warning: Unknown Amqp type: %s", $type), E_USER_WARNING); $implType = ($tableField) ? $this->getTableTypeForValue($value) : $this->getXmlTypeForValue($value); if (! $implType) { trigger_error("Warning: no type mapping found for input type or value - nothing written", E_USER_WARNING); return; } } $r = $this->{"write$implType"}($value); if ($implType === 'Boolean') { $this->binPackOffset++; } else { $this->binPackOffset = 0; } } private function writeTable ($val) { if (is_array($val)) { $val = new Table($val); } else if (! ($val instanceof Table)) { $val = array(); } $w = new Writer; foreach ($val as $fName => $field) { $w->writeShortString($fName); $w->writeShortShortUInt(ord($field->getType())); $w->write($field->getValue(), $field->getType(), true); } $this->bin .= pack('N', strlen($w->bin)) . $w->bin; } private function writeFieldArray (array $arr) { $p = strlen($this->bin); foreach ($arr as $item) { if (! ($item instanceof TableField)) { $item = new TableField($item); } $this->writeShortShortUInt(ord($item->getType())); $this->write($item->getValue(), $item->getType(), true); } $p2 = strlen($this->bin); $binSav = $this->bin; $this->bin = ''; $this->writeLongUInt($p2 - $p); $binLen = $this->bin; $this->bin = substr($binSav, 0, $p) . $binLen . substr($binSav, $p); } private function writeBoolean ($val) { if ($this->binPackOffset == 0) { if ($val) { $this->bin .= pack('C', 1); } else { $this->bin .= pack('C', 0); } } else { $tmp = unpack('C', substr($this->bin, -1)); $b = reset($tmp); if ($val) { $b += pow(2, $this->binPackOffset); } if ($this->binPackOffset > 6) { $this->binPackOffset = -1; } $this->bin = substr($this->bin, 0, -1) . pack('C', $b); } } private function writeShortShortInt ($val) { $this->bin .= pack('c', (int) $val); } private function writeShortShortUInt ($val) { $this->bin .= pack('C', (int) $val); } private function writeShortInt ($val) { $this->bin .= pack('s', (int) $val); } private function writeShortUInt ($val) { $this->bin .= pack('n', (int) $val); } private function writeLongInt ($val) { $this->bin .= pack('L', (int) $val); } private function writeLongUInt ($val) { $this->bin .= pack('N', (int) $val); } private function writeLongLongInt ($val) { error("Unimplemented *write* method %s", __METHOD__); } private function writeLongLongUInt ($val) { $tmp = array(); for ($i = 0; $i < 8; $i++) { $tmp[] = $val & 255; $val = ($val >> 8); } foreach (array_reverse($tmp) as $octet) { $this->bin .= chr($octet); } } private function writeFloat ($val) { $this->bin .= pack('f', (float) $val); } private function writeDouble ($val) { $this->bin .= pack('d', (float) $val); } private function writeDecimalValue ($val) { if (! ($val instanceof Decimal)) { $val = new Decimal($val); } $this->writeShortShortUInt($val->getScale()); $this->writeLongUInt($val->getUnscaled()); } private function writeShortString ($val) { $this->writeShortShortUInt(strlen($val)); $this->bin .= $val; } private function writeLongString ($val) { $this->writeLongUInt(strlen($val)); $this->bin .= $val; } }
   class Method implements \Serializable { const ST_METH_READ = 1; const ST_CHEAD_READ = 2; const ST_BODY_READ = 4; const PARTIAL_FRAME = 7; private static $PlainPFields = array('rcState', 'mode', 'fields', 'classFields', 'content', 'frameSize', 'wireChannel', 'isHb', 'wireMethodId', 'wireClassId', 'contentSize'); private $rcState = 0; private $methProto; private $classProto; private $mode; private $fields = array(); private $classFields = array(); private $content; private $frameSize; private $wireChannel = null; private $wireMethodId; private $wireClassId; private $contentSize; private $isHb = false; private $protoLoader; public $amqpClass; function serialize () { if ($this->mode != 'read') { trigger_error("Only read mode methods should be serialised", E_USER_WARNING); return null; } $ret = array(); $ret['plainFields'] = array(); foreach (self::$PlainPFields as $k) { $ret['plainFields'][$k] = $this->$k; } if ($this->methProto && $this->classProto) { $ret['protos'] = array(get_class($this->methProto), get_class($this->classProto)); } return serialize($ret); } function unserialize ($s) { $state = unserialize($s); foreach (self::$PlainPFields as $k) { $this->$k = $state['plainFields'][$k]; } if (array_key_exists('protos', $state)) { list($mc, $cc) = $state['protos']; $this->methProto = new $mc; $this->classProto = new $cc; $this->amqpClass = sprintf('%s.%s', $this->classProto->getSpecName(), $this->methProto->getSpecName()); } } function setProtocolLoader ($l) { $this->protoLoader = $l; } function __construct (abstrakt\XmlSpecMethod $src = null, $chan = 0) { if ($src instanceof abstrakt\XmlSpecMethod) { $this->methProto = $src; $this->classProto = $this->methProto->getClass(); $this->mode = 'write'; $this->wireChannel = $chan; $this->amqpClass = sprintf('%s.%s', $this->classProto->getSpecName(), $this->methProto->getSpecName()); } else { $this->mode = 'read'; } } function canReadFrom (Reader $src) { if (is_null($this->wireChannel)) { return true; } if (true === ($_fh = $this->extractFrameHeader($src))) { return false; } list($wireType, $wireChannel, $wireSize) = $_fh; $ret = ($wireChannel == $this->wireChannel); $src->rewind(7); return $ret; } function readConstruct (Reader $src, \Closure $protoLoader) { if ($this->mode == 'write') { trigger_error('Invalid read construct operation on a read mode method', E_USER_WARNING); return false; } $FRME = 206; $break = false; $ret = true; $this->protoLoader = $protoLoader; while (! $src->isSpent()) { if (true === ($_fh = $this->extractFrameHeader($src))) { $ret = self::PARTIAL_FRAME; break; } else { list($wireType, $wireChannel, $wireSize) = $_fh; } if (! $this->wireChannel) { $this->wireChannel = $wireChannel; } else if ($this->wireChannel != $wireChannel) { $src->rewind(7); return true; } if ($src->isSpent($wireSize + 1)) { $src->rewind(7); $ret = self::PARTIAL_FRAME; break; } switch ($wireType) { case 1: $this->readMethodContent($src, $wireSize); if (! $this->methProto->getSpecHasContent()) { $break = true; } break; case 2: $this->readContentHeaderContent($src, $wireSize); break; case 3: $this->readBodyContent($src, $wireSize); if ($this->readConstructComplete()) { $break = true; } break; case 8: $break = $ret = $this->isHb = true; break; default: throw new \Exception(sprintf("Unsupported frame type %d", $wireType), 8674); } if ($src->read('octet') != $FRME) { throw new \Exception(sprintf("Framing exception - missed frame end (%s) - (%d,%d,%d,%d) [%d, %d]", $this->amqpClass, $this->rcState, $break, $src->isSpent(), $this->readConstructComplete(), strlen($this->content), $this->contentSize ), 8763); } if ($break) { break; } } return $ret; } private function extractFrameHeader(Reader $src) { if ($src->isSpent(7)) { return true; } if (null === ($wireType = $src->read('octet'))) { throw new \Exception('Failed to read type from frame', 875); } else if (null === ($wireChannel = $src->read('short'))) { throw new \Exception('Failed to read channel from frame', 9874); } else if (null === ($wireSize = $src->read('long'))) { throw new \Exception('Failed to read size from frame', 8715); } return array($wireType, $wireChannel, $wireSize); } private function readMethodContent (Reader $src, $wireSize) { $st = $src->p; $this->wireClassId = $src->read('short'); $this->wireMethodId = $src->read('short'); $protoLoader = $this->protoLoader; if (! ($this->classProto = $protoLoader('ClassFactory', 'GetClassByIndex', array($this->wireClassId)))) { throw new \Exception(sprintf("Failed to construct class prototype for class ID %s", $this->wireClassId), 9875); } else if (! ($this->methProto = $this->classProto->getMethodByIndex($this->wireMethodId))) { throw new \Exception("Failed to construct method prototype", 5645); } $this->amqpClass = sprintf('%s.%s', $this->classProto->getSpecName(), $this->methProto->getSpecName()); foreach ($this->methProto->getFields() as $f) { $this->fields[$f->getSpecFieldName()] = $src->read($f->getSpecDomainType()); } $en = $src->p; if ($wireSize != ($en - $st)) { throw new \Exception("Invalid method frame size", 9845); } $this->rcState = $this->rcState | self::ST_METH_READ; } private function readContentHeaderContent (Reader $src, $wireSize) { $st = $src->p; $wireClassId = $src->read('short'); $src->read('short'); $this->contentSize = $src->read('longlong'); if ($wireClassId != $this->wireClassId) { throw new \Exception(sprintf("Unexpected class in content header (%d, %d) - read state %d", $wireClassId, $this->wireClassId, $this->rcState), 5434); } $binFlags = ''; while (true) { if (null === ($fBlock = $src->read('short'))) { throw new \Exception("Failed to read property flag block", 4548); } $binFlags .= str_pad(decbin($fBlock), 16, '0', STR_PAD_LEFT); if (0 !== (strlen($binFlags) % 16)) { throw new \Exception("Unexpected message property flags", 8740); } if (substr($binFlags, -1) == '1') { $binFlags = substr($binFlags, 0, -1); } else { break; } } foreach ($this->classProto->getFields() as $i => $f) { if ($f->getSpecFieldDomain() == 'bit') { $this->classFields[$f->getSpecFieldName()] = (boolean) substr($binFlags, $i, 1); } else if (substr($binFlags, $i, 1) == '1') { $this->classFields[$f->getSpecFieldName()] = $src->read($f->getSpecFieldDomain()); } else { $this->classFields[$f->getSpecFieldName()] = null; } } $en = $src->p; if ($wireSize != ($en - $st)) { throw new \Exception("Invalid content header frame size", 2546); } $this->rcState = $this->rcState | self::ST_CHEAD_READ; } private function readBodyContent (Reader $src, $wireSize) { $this->content .= $src->readN($wireSize); $this->rcState = $this->rcState | self::ST_BODY_READ; } function readConstructComplete () { if ($this->isHb) { return true; } else if (! $this->methProto) { return false; } else if (! $this->methProto->getSpecHasContent()) { return (boolean) $this->rcState & self::ST_METH_READ; } else { return ($this->rcState & self::ST_CHEAD_READ) && (strlen($this->content) >= $this->contentSize); } } function setField ($name, $val) { if ($this->mode == 'read') { trigger_error('Setting field value for read constructed method', E_USER_WARNING); } else if (in_array($name, $this->methProto->getSpecFields())) { $this->fields[$name] = $val; } else if (in_array($name, $this->classProto->getSpecFields())) { $this->classFields[$name] = $val; } else { $warns = sprintf("Field %s is invalid for Amqp message type %s", $name, $this->amqpClass); trigger_error($warns, E_USER_WARNING); } } function getField ($name) { if (array_key_exists($name, $this->fields)) { return $this->fields[$name]; } else if (array_key_exists($name, $this->classFields)) { return $this->classFields[$name]; } else if (! in_array($name, array_merge($this->classProto->getSpecFields(), $this->methProto->getSpecFields()))) { $warns = sprintf("Field %s is invalid for Amqp message type %s", $name, $this->amqpClass); trigger_error($warns, E_USER_WARNING); } } function getFields () { return array_merge($this->classFields, $this->fields); } function setContent ($content) { if ($this->mode == 'read') { trigger_error('Setting content value for read constructed method', E_USER_WARNING); } else if (strlen($content)) { if (! $this->methProto->getSpecHasContent()) { trigger_error('Setting content value for a method which doesn\'t take content', E_USER_WARNING); } $this->content = $content; } } function getContent () { if (! $this->methProto->getSpecHasContent()) { trigger_error('Invalid serialize operation on a method which doesn\'t take content', E_USER_WARNING); return ''; } return $this->content; } function getMethodProto () { return $this->methProto; } function getClassProto () { return $this->classProto; } function getWireChannel () { return $this->wireChannel; } function getWireSize () { return $this->wireSize; } function getWireClassId () { return $this->wireClassId; } function getWireMethodId () { return $this->wireMethodId; } function setMaxFrameSize ($max) { $this->frameSize = $max; } function setWireChannel ($chan) { $this->wireChannel = $chan; } function isHeartbeat () { return $this->isHb; } function toBin (\Closure $protoLoader) { if ($this->mode == 'read') { trigger_error('Invalid serialize operation on a read mode method', E_USER_WARNING); return ''; } $frme = $protoLoader('ProtoConsts', 'GetConstant', array('FRAME_END')); $w = new Writer; $tmp = $this->getMethodBin(); $w->write(1, 'octet'); $w->write($this->wireChannel, 'short'); $w->write(strlen($tmp), 'long'); $buff = $w->getBuffer() . $tmp . $frme; $ret = array($buff); if ($this->methProto->getSpecHasContent()) { $w = new Writer; $tmp = $this->getContentHeaderBin(); $w->write(2, 'octet'); $w->write($this->wireChannel, 'short'); $w->write(strlen($tmp), 'long'); $ret[] = $w->getBuffer() . $tmp . $frme; $tmp = (string) $this->content; $i = 0; $frameSize = $this->frameSize - 8; while (true) { $chunk = substr($tmp, ($i * $frameSize), $frameSize); if (strlen($chunk) == 0) { break; } $w = new Writer; $w->write(3, 'octet'); $w->write($this->wireChannel, 'short'); $w->write(strlen($chunk), 'long'); $ret[] = $w->getBuffer() . $chunk . $frme; $i++; } } return $ret; } private function getMethodBin () { if ($this->mode == 'read') { trigger_error('Invalid serialize operation on a read mode method', E_USER_WARNING); return ''; } $src = new Writer; $src->write($this->classProto->getSpecIndex(), 'short'); $src->write($this->methProto->getSpecIndex(), 'short'); foreach ($this->methProto->getFields() as $f) { $name = $f->getSpecFieldName(); $type = $f->getSpecDomainType(); $val = ''; if (array_key_exists($name, $this->fields)) { $val = $this->fields[$name]; if (! $f->validate($val)) { $warns = sprintf("Field %s of method %s failed validation by protocol binding class %s", $name, $this->amqpClass, get_class($f)); trigger_error($warns, E_USER_WARNING); } } $src->write($val, $type); } return $src->getBuffer(); } private function getContentHeaderBin () { if ($this->mode == 'read') { trigger_error('Invalid serialize operation on a read mode method', E_USER_WARNING); return ''; } else if (! $this->methProto->getSpecHasContent()) { trigger_error('Invalid serialize operation on a method which doesn\'t take content', E_USER_WARNING); return ''; } $src = new Writer; $src->write($this->classProto->getSpecIndex(), 'short'); $src->write(0, 'short'); $src->write(strlen($this->content), 'longlong'); $pFlags = ''; $pChunks = 0; $pList = ''; $src2 = new Writer; foreach ($this->classProto->getFields() as $i => $f) { if (($i % 15) == 0) { if ($i > 0) { $pFlags .= '1'; } $pChunks++; } $fName = $f->getSpecFieldName(); $dName = $f->getSpecFieldDomain(); if (array_key_exists($fName, $this->classFields) && ! ($dName == 'bit' && ! $this->classFields[$fName])) { $pFlags .= '1'; } else { $pFlags .= '0'; } if (array_key_exists($fName, $this->classFields) && $dName != 'bit') { if (! $f->validate($this->classFields[$fName])) { trigger_error("Field {$fName} of method {$this->amqpClass} is not valid", E_USER_WARNING); } $src2->write($this->classFields[$fName], $f->getSpecDomainType()); } } if ($pFlags && (strlen($pFlags) % 16) !== 0) { $pFlags .= str_repeat('0', 16 - (strlen($pFlags) % 16)); } $pBuff = ''; for ($i = 0; $i < $pChunks; $i++) { $pBuff .= pack('n', bindec(substr($pFlags, $i*16, 16))); } return $src->getBuffer() . $pBuff . $src2->getBuffer(); } function isResponse (Method $other) { if ($exp = $this->methProto->getSpecResponseMethods()) { if ($this->classProto->getSpecName() != $other->classProto->getSpecName() || $this->wireChannel != $other->wireChannel) { return false; } else { return in_array($other->methProto->getSpecName(), $exp); } } else { trigger_error("Method does not expect a response", E_USER_WARNING); return false; } } }
   abstract class Protocol { private static $Versions = array('0.9.1'); private static $ImplTypes = array('Table', 'Boolean', 'ShortShortInt', 'ShortShortUInt', 'ShortInt', 'ShortUInt', 'LongInt', 'LongUInt', 'LongLongInt', 'LongLongUInt', 'Float', 'Double', 'DecimalValue', 'ShortString', 'LongString', 'FieldArray', 'Timestamp'); private static $XmlTypesMap = array('bit' => 'Boolean', 'octet' => 'ShortShortUInt', 'short' => 'ShortUInt', 'long' => 'LongUInt', 'longlong' => 'LongLongUInt', 'shortstr' => 'ShortString', 'longstr' => 'LongString', 'timestamp' => 'LongLongUInt', 'table' => 'Table'); private static $AmqpTableMap = array('t' => 'ShortShortUInt', 'b' => 'ShortShortInt', 'B' => 'ShortShortUInt', 'U' => 'ShortInt', 'u' => 'ShortUInt', 'I' => 'LongInt', 'i' => 'LongUInt', 'L' => 'LongLongInt', 'l' => 'LongLongUInt', 'f' => 'Float', 'd' => 'Double', 'D' => 'DecimalValue', 's' => 'ShortString', 'S' => 'LongString', 'A' => 'FieldArray', 'T' => 'LongLongUInt', 'F' => 'Table'); protected $bin; static function GetXmlTypes () { return self::$XmlTypesMap; } protected function getImplForXmlType($t) { return isset(self::$XmlTypesMap[$t]) ? self::$XmlTypesMap[$t] : null; } protected function getImplForTableType($t) { return isset(self::$AmqpTableMap[$t]) ? self::$AmqpTableMap[$t] : null; } protected function getTableTypeForValue($val) { if (is_bool($val)) { return 't'; } else if (is_int($val)) { if ($val > 0) { if ($val < 256) { return 'B'; } else if ($val < 65536) { return 'u'; } else if ($val < 4294967296) { return 'i'; } else { return 'l'; } } else if ($val < 0) { $val = abs($val); if ($val < 256) { return 'b'; } else if ($val < 65536) { return 'U'; } else if ($val < 4294967296) { return 'I'; } else { return 'L'; } } else { return 'B'; } } else if (is_float($val)) { return 'f'; } else if (is_string($val)) { return 'S'; } else if (is_array($val)) { $isArray = false; foreach (array_keys($val) as $k) { if (is_int($k)) { $isArray = true; break; } } return $isArray ? 'A' : 'F'; } else if ($val instanceof Decimal) { return 'D'; } return null; } protected function getXmlTypeForValue($val) { if (is_bool($val)) { return 'bit'; } else if (is_int($val)) { $val = abs($val); if ($val < 256) { return 'octet'; } else if ($val < 65536) { return 'short'; } else if ($val < 4294967296) { return 'long'; } else { return 'longlong'; } } else if (is_string($val)) { return (strlen($val) < 255) ? 'shortstr' : 'longstr'; } else if (is_array($val) || $val instanceof Table) { return 'table'; } return null; } function getBuffer() { return $this->bin; } } 
   class Table implements \ArrayAccess, \Iterator { const ITER_MODE_SIMPLE = 1; const ITER_MODE_TYPED = 2; private $data = array(); private $iterMode = self::ITER_MODE_SIMPLE; private $iterP = 0; private $iterK; static function IsValidKey($k) { return is_string($k) && $k && (strlen($k) < 129) && preg_match("/[a-zA-Z\$\#][a-zA-Z-_\$\#]*/", $k); } function __construct(array $data = array()) { foreach ($data as $name => $av) { $this->offsetSet($name, $av); } } function offsetExists($k) { return array_key_exists($k, $this->data); } function offsetGet($k) { if (! $this->offsetExists($k)) { trigger_error(sprintf("Offset not found [0]: %s", $k), E_USER_WARNING); return null; } return $this->data[$k]; } function offsetSet($k, $v) { if ( ! self::IsValidKey($k)) { throw new \Exception("Invalid table key", 7255); } else if (! ($v instanceof TableField)) { $v = new TableField($v); } $this->data[$k] = $v; } function offsetUnset($k) { if (! isset($this->data[$k])) { trigger_error(sprintf("Offset not found [1]: %s", $k), E_USER_WARNING); } else { unset($this->data[$n]); } } function getArrayCopy() { $ac = array(); foreach ($this->data as $k => $v) { $ac[$k] = $v->getValue(); } return $ac; } function rewind() { $this->iterP = 0; $this->iterK = array_keys($this->data); } function current() { return $this->data[$this->iterK[$this->iterP]]; } function key() { return $this->iterK[$this->iterP]; } function next() { $this->iterP++; } function valid() { return isset($this->iterK[$this->iterP]); } } 
   class TableField extends Protocol { protected $val; protected $type; function __construct($val, $type=false) { $this->val = $val; $this->type = ($type === false) ? $this->getTableTypeForValue($val) : $type; } function getValue() { return $this->val; } function setValue($val) { $this->val = $val; } function getType() { return $this->type; } function __toString() { return (string) $this->val; } } 
   class Reader extends Protocol { public $p = 0; public $binPackOffset = 0; public $binBuffer; public $binLen = 0; function __construct ($bin) { $this->bin = $bin; $this->binLen = strlen($bin); } function isSpent ($n = false) { $n = ($n === false) ? 0 : $n - 1; return ($this->p + $n >= $this->binLen); } function bytesRemaining () { return $this->binLen - $this->p; } function getRemainingBuffer () { $r = substr($this->bin, $this->p); $this->p = $this->binLen - 1; return $r; } function append ($bin) { $this->bin .= $bin; $this->binLen += strlen($bin); } function rewind ($n) { $this->p -= $n; } function readN ($n) { $ret = substr($this->bin, $this->p, $n); $this->p += strlen($ret); return $ret; } function read ($type, $tableField=false) { $implType = ($tableField) ? $this->getImplForTableType($type) : $this->getImplForXmlType($type); if (! $implType) { trigger_error("Warning: no type mapping found for input type or value - nothing read", E_USER_WARNING); return; } $r = $this->{"read$implType"}(); if ($implType === 'Boolean') { if ($this->binPackOffset++ > 6) { $this->binPackOffset = 0; } } else { $this->binPackOffset = 0; } return $r; } private function readTable () { $tLen = $this->readLongUInt(); $tEnd = $this->p + $tLen; $t = new Table; while ($this->p < $tEnd) { $fName = $this->readShortString(); $fType = chr($this->readShortShortUInt()); $t[$fName] = new TableField($this->read($fType, true), $fType); } return $t; } private function readBoolean () { if ($this->binPackOffset == 0) { $tmp = unpack('C', substr($this->bin, $this->p++, 1)); $this->binBuffer = reset($tmp); } return ($this->binBuffer & (1 << $this->binPackOffset)) ? 1 : 0; } private function readShortShortInt () { $tmp = unpack('c', substr($this->bin, $this->p++, 1)); $i = reset($tmp); return $i; } private function readShortShortUInt () { $tmp = unpack('C', substr($this->bin, $this->p++, 1)); $i = reset($tmp); return $i; } private function readShortInt () { $tmp = unpack('s', substr($this->bin, $this->p, 2)); $i = reset($tmp); $this->p += 2; return $i; } private function readShortUInt () { $tmp = unpack('n', substr($this->bin, $this->p, 2)); $i = reset($tmp); $this->p += 2; return $i; } private function readLongInt () { $tmp = unpack('L', substr($this->bin, $this->p, 4)); $i = reset($tmp); $this->p += 4; return $i; } private function readLongUInt () { $tmp = unpack('N', substr($this->bin, $this->p, 4)); $i = reset($tmp); $this->p += 4; return $i; } private function readLongLongInt () { trigger_error("Unimplemented read method %s", __METHOD__); } private function readLongLongUInt () { $byte = substr($this->bin, $this->p++, 1); $ret = ord($byte); for ($i = 1; $i < 8; $i++) { $ret = ($ret << 8) + ord(substr($this->bin, $this->p++, 1)); } return $ret; } private function readFloat () { $tmp = unpack('f', substr($this->bin, $this->p, 4)); $i = reset($tmp); $this->p += 4; return $i; } private function readDouble () { $tmp = unpack('d', substr($this->bin, $this->p, 8)); $i = reset($tmp); $this->p += 8; return $i; } private function readDecimalValue () { $scale = $this->readShortShortUInt(); $unscaled = $this->readLongUInt(); return new Decimal($unscaled, $scale); } private function readShortString () { $l = $this->readShortShortUInt(); $s = substr($this->bin, $this->p, $l); $this->p += $l; return $s; } private function readLongString () { $l = $this->readLongUInt(); $s = substr($this->bin, $this->p, $l); $this->p += $l; return $s; } private function readFieldArray () { $aLen = $this->readLongUInt(); $aEnd = $this->p + $aLen; $a = array(); while ($this->p < $aEnd) { $t = chr($this->readShortShortUInt()); $a[] = $this->read($t, true); } return $a; } } 
   class Decimal { const BC_SCALE_DEFAULT = 8; private $unscaled; private $scale; private $bcScale = self::BC_SCALE_DEFAULT; function __construct($unscaled, $scale=false) { if ($scale !== false) { if ($scale < 0 || $scale > 255) { throw new \Exception("Scale out of range", 9876); } $this->unscaled = (string) $unscaled; $this->scale = (string) $scale; } else if (is_float($unscaled)) { list($whole, $frac) = explode('.', (string) $unscaled); $frac = rtrim($frac, '0'); $this->unscaled = $whole . $frac; $this->scale = strlen($frac); } else if (is_int($unscaled)) { $this->unscaled = $unscaled; $this->scale = 0; } else { throw new \Exception("Unable to construct a decimal", 48943); } if ($this->scale > 255) { throw new \Exception("Decimal scale is out of range", 7843); } } function getUnscaled() { return $this->unscaled; } function getScale() { return $this->scale; } function setBcScale($i) { $this->bcScale = (int) $i; } function toBcString() { return bcdiv($this->unscaled, bcpow('10', $this->scale, $this->bcScale), $this->bcScale); } function toFloat() { return (float) $this->toBcString(); } function __toString() { return $this->toBcString(); } } 