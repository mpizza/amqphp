<?php
 namespace amqphp\wire; final class Hexdump { final static function hexdump ($buff) { static $f, $f2; if ($buff === '') { return "00000000\n"; } if (is_null($f)) { $f = function ($char) { return sprintf('%02s', dechex(ord($char))); }; $f2 = function ($char) { $ord = ord($char); return ($ord > 31 && $ord < 127) ? chr($ord) : '.'; }; } $l = strlen($buff); $ret = ''; for ($i = 0; $i < $l; $i += 16) { $line = substr($buff, $i, 8); $ll = $offLen = strlen($line); $rem = (8 - $ll) * 3; $hexes = vsprintf(str_repeat('%3s', $ll), array_map($f, str_split($line, 1))); $chars = '|' . vsprintf(str_repeat('%s', $ll), array_map($f2, str_split($line, 1))); $lBuff = sprintf("%08s %s", dechex($i), $hexes); if ($line = substr($buff, $i + 8, 8)) { $ll = strlen($line); $offLen += $ll; $rem = (8 - $ll) * 3 + 1; $hexes = vsprintf(str_repeat('%3s', $ll), array_map($f, str_split($line, 1))); $chars .= ' '. vsprintf(str_repeat('%s', $ll), array_map($f2, str_split($line, 1))); $lBuff .= sprintf(" %s%{$rem}s %s|\n", $hexes, ' ', $chars); } else { $lBuff .= ' ' . str_repeat(" ", $rem + 26) . $chars . "|\n"; } $ret .= $lBuff; } return sprintf("%s%08s\n", $ret, dechex($l)); } }