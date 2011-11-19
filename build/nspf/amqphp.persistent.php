<?php
namespace amqphp\persistent;
 class PChannel extends \amqphp\Channel implements \Serializable { public $suspendOnSerialize = false; public $resumeOnHydrate = false; private static $PersProps = array('chanId', 'flow', 'frameMax', 'confirmSeqs', 'confirmSeq', 'confirmMode', 'isOpen', 'callbackHandler', 'suspendOnSerialize', 'resumeOnHydrate'); function serialize () { $data = array(); foreach (self::$PersProps as $k) { $data[$k] = $this->$k; } $data['consumers'] = array(); foreach ($this->consumers as $cons) { if ($cons[0] instanceof \Serializable && $cons[2] == 'READY') { $data['consumers'][] = $cons; } } return serialize($data); } function unserialize ($data) { $data = unserialize($data); foreach (self::$PersProps as $p) { $this->$p = $data[$p]; } foreach ($data['consumers'] as $i => $c) { $this->consumers[$i] = array($c[0], $c[1], $c[2]); } } }
 class APCPersistenceHelper implements PersistenceHelper { private $data; private $uk; function setUrlKey ($k) { if (is_null($k)) { throw new \Exception("Url key cannot be null", 8260); } $this->uk = $k; } function getData () { return $this->data; } function setData ($data) { $this->data = $data; } private function getKey () { if (is_null($this->uk)) { throw new \Exception("Url key cannot be null", 8261); } return sprintf('apc.amqphp.%s.%s', getmypid(), $this->uk); } function save () { $k = $this->getKey(); return apc_store($k, $this->data); } function load () { $success = false; $this->data = apc_fetch($this->getKey(), $success); return $success; } function destroy () { return apc_delete($this->getKey()); } }
 use amqphp\protocol, amqphp\wire;  class PConnection extends \amqphp\Connection implements \Serializable { const SOCK_NEW = 1; const SOCK_REUSED = 2; private static $BasicProps = array('capabilities', 'socketImpl', 'protoImpl', 'socketParams', 'vhost', 'frameMax', 'chanMax', 'signalDispatch', 'nextChan', 'unDelivered', 'unDeliverable', 'incompleteMethods', 'readSrc'); private $pHelper; public $pHelperImpl; private $stateFlag = 0; const ST_CONSTR = 1; const ST_UNSER = 2; const ST_SER = 4; final function __construct (array $params = array()) { $this->stateFlag |= self::ST_CONSTR; if (isset($params['heartbeat']) && $params['heartbeat'] > 0) { throw new \Exception("Persistent connections cannot use a heatbeat", 24803); } if (! array_key_exists('socketImpl', $params)) { $params['socketImpl'] = '\\amqphp\\StreamSocket'; } else if ($params['socketImpl'] != '\\amqphp\\StreamSocket') { throw new \Exception("Persistent connections must use the StreamSocket socket implementation", 24804); } if (! array_key_exists('socketFlags', $params)) { $params['socketFlags'] = array('STREAM_CLIENT_PERSISTENT'); } else if ( ! in_array('STREAM_CLIENT_PERSISTENT', $params['socketFlags'])) { $params['socketFlags'][] = 'STREAM_CLIENT_PERSISTENT'; } parent::__construct($params); } function connect () { if ($this->connected) { trigger_error("PConnection is connected already", E_USER_WARNING); return; } if (($args = func_get_args()) && is_array($args[0])) { trigger_error("Setting connection parameters via. the connect method is deprecated, please specify " . "these parameters in the Connection class constructor instead.", E_USER_DEPRECATED); $this->setConnectionParams($args[0]); } $this->initSocket(); $this->sock->connect(); if ($this->sock->isReusedPSock()) { $this->wakeup(); } else { $this->doConnectionStartup(); if ($ph = $this->getPersistenceHelper()) { $ph->destroy(); } } } function shutdown () { $ph = $this->getPersistenceHelper(); parent::shutdown(); if ($ph) { $ph->destroy(); } } protected function initNewChannel ($impl=null) { $impl = __NAMESPACE__ . "\\PChannel"; return parent::initNewChannel($impl); } private function getPersistenceHelper () { if (! $this->connected) { throw new \Exception("PConnection persistence helper cannot be created before the connection is open", 3789); } else if (! $this->pHelperImpl) { return false; } if (is_null($this->pHelper)) { $c = $this->pHelperImpl; $this->pHelper = new $c; if (! ($this->pHelper instanceof PersistenceHelper)) { throw new \Exception("PConnection persistence helper implementation is invalid", 26934); } $this->pHelper->setUrlKey($this->sock->getCK()); } return $this->pHelper; } function getPersistenceStatus () { if (! $this->connected) { return 0; } else if ($this->sock->isReusedPSock()) { return self::SOCK_REUSED; } else { return self::SOCK_NEW; } } function sleep () { if (! ($ph = $this->getPersistenceHelper())) { throw new \Exception("Failed to load a persistence helper during sleep", 10785); } $ph->setData($this->serialize()); $ph->save(); } function serialize () { $z = $data = array(); foreach ($this->chans as $chan) { if ($chan->suspendOnSerialize && ! $chan->isSuspended()) { $chan->toggleFlow(); } } $z[0] = $this->chans; foreach (self::$BasicProps as $k) { if (in_array($k, array('readSrc', 'incompleteMethods', 'unDelivered', 'unDeliverable')) && $this->$k) { trigger_error("PConnection will persist application data ({$k})", E_USER_WARNING); } $data[$k] = $this->$k; } $z[1] = $data; $this->stateFlag |= self::ST_SER; return serialize($z); } function unserialize ($serialised) { $data = unserialize($serialised); $rewake = false; if ($this->stateFlag & self::ST_UNSER) { throw new \Exception("PConnection is already unserialized", 2886); } else if (! ($this->stateFlag & self::ST_CONSTR)) { $this->__construct(); $rewake = true; } foreach (self::$BasicProps as $k) { $this->$k = $data[1][$k]; } if ($rewake) { $this->initSocket(); $this->sock->connect(); if (! $this->sock->isReusedPSock()) { throw new \Exception("Persisted connection woken up with a fresh socket connection", 9249); } foreach (self::$BasicProps as $k) { if ($k == 'vhost' && $data[1][$k] != $this->sock->getVHost()) { throw new \Exception("Persisted connection woken up as different VHost", 9250); } } $this->connected = true; } if (isset($data[0])) { $this->chans = $data[0]; foreach ($this->chans as $chan) { $chan->setConnection($this); } } $this->stateFlag |= self::ST_UNSER; foreach ($this->chans as $chan) { if ($chan->resumeOnHydrate && $chan->isSuspended()) { $chan->toggleFlow(); } } } private function wakeup () { $this->connected = true; if (! ($ph = $this->getPersistenceHelper())) { throw new \Exception("Failed to load persistence helper during wakeup", 1798); } if (! $ph->load()) { try { $e = null; $this->shutdown(); } catch (\Exception $e) { } throw new \Exception('Failed to reload amqp connection cache during wakeup', 8543, $e); } $this->unserialize($ph->getData()); } }
 class FilePersistenceHelper implements PersistenceHelper { const TEMP_DIR = '/tmp'; private $data; private $uk; private $tmpDir = self::TEMP_DIR; function setUrlKey ($k) { if (is_null($k)) { throw new \Exception("Url key cannot be null", 8260); } $this->uk = $k; } function setTmpDir ($tmpDir) { $this->tmpDir = $tmpDir; } function getData () { return $this->data; } function setData ($data) { $this->data = $data; } function getTmpFile () { if (is_null($this->uk)) { throw new \Exception("Url key cannot be null", 8261); } return sprintf('%s%sapc.amqphp.%s.%s', $this->tmpDir, DIRECTORY_SEPARATOR, getmypid(), $this->uk); } function save () { return file_put_contents($this->getTmpFile(), (string) $this->data); } function load () { $this->data = file_get_contents($this->getTmpFile()); return ($this->data !== false); } function destroy () { return @unlink($this->getTmpFile()); } }
 interface PersistenceHelper { function setUrlKey ($k); function getData (); function setData ($data); function save (); function load (); function destroy (); }