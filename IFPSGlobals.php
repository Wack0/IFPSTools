<?php
// Global functions, used by multiple classes.

final class IFPSGlobals {
	public static $isVoid = false;
	
	public static function ReadUInt32($data,$length,&$offset) {
		if ($length < $offset + 4) throw new Exception("Reached end of file");
		$offset += 4;
		return current(unpack("V",substr($data,$offset - 4,4)));
	}

	public static function MakeHash($s) {
		$ret = 0;
		for ($i = 0; $i < strlen($s); $i++) {
			$ret = (($ret << 7) | ($ret << 25)) + ord($s[$i]);
		}
		return $ret;
	}

	public static function hex2float($number) {
		$binfinal = sprintf("%032b",hexdec($number));
		$sign = substr($binfinal, 0, 1);
		$exp = substr($binfinal, 1, 8);
		$mantissa = "1".substr($binfinal, 9);
		$mantissa = str_split($mantissa);
		$exp = bindec($exp)-127;
		$significand=0;
		for ($i = 0; $i < 24; $i++) {
			$significand += (1 / pow(2,$i))*$mantissa[$i];
		}
		return $significand * pow(2,$exp) * ($sign*-2+1);
	}

	// BUGBUG: This doesn't work, and there will probably be no way to ever get such a function to work.
	public static function hex_extended2float($number) {
		$binfinal = sprintf("%080b",hexdec($number));
		$sign = substr($binfinal, 0, 1);
		$exp = substr($binfinal, 1, 15);
		$mantissa = "1".substr($binfinal, 16);
		$mantissa = str_split($mantissa);
		$exp = bindec($exp)-127;
		$significand=0;
		for ($i = 0; $i < 65; $i++) {
			$significand += (1 / pow(2,$i))*$mantissa[$i];
		}
		return $significand * pow(2,$exp) * ($sign*-2+1);
	}

	public static function isLittleEndian() {
		return current(unpack('S',"\x01\x00")) === 1;
	}

	public static function ParseVar($a) {
		if ($a < 0x40000000) return (object)array("type"=>"Global","index"=>$a);
		$a -= 0x60000000;
		if (($a == -1) && (!self::$isVoid)) return (object)array("type"=>"RetVal","index"=>$a);
		if ($a >= 0) return (object)array("type"=>"Var","index"=>$a);
		return (object)array("type"=>"Arg","index"=>((-$a) - (!self::$isVoid)));
	}

	public static function ParseFDecl($f) {
		$ret = (object) array();
		$offset = 0;
		if (substr($f,0,4) == "dll:") {
			$f = explode("\x00",$f,3);
			$ret->type = 0;
			$ret->dll = substr($f[0],4);
			$ret->func = $f[1];
			$restlen = strlen($f[2]);
			$ret->CallingConv = current(unpack('C',substr($f[2],$offset++,1)));
			switch ($ret->CallingConv) {
				case 0:
					$ret->CallingConv = "register";
					break;
				case 1:
					$ret->CallingConv = "pascal";
					break;
				case 2:
					$ret->CallingConv = "cdecl";
					break;
				case 3:
					$ret->CallingConv = "stdcall";
					break;
				default:
					throw new Exception("Unhandled calling convention ".$ret->CallingConv);
			}
			$ret->DelayLoad = (bool) current(unpack('C',substr($f[2],$offset++,1)));
			$ret->LoadWithAlteredSearchPath = (bool) current(unpack('C',substr($f[2],$offset++,1)));
			$ret->isVoid = !( (bool) ( current(unpack('C',substr($f[2],$offset++,1))) ) );
			$f = $f[2];
		} elseif (substr($f,0,6) == "class:") {
			$f = substr($f,6);
			if ($f == "+") {
				$ret->type = 1;
				$ret->classname = "Class";
				$ret->func = "CastToType";
				$ret->isVoid = 0;
				$ret->CallingConv = "pascal";
				$ret->params = array((object) array("modeIn"=> false),(object) array("modeIn"=> false));
				return $ret;
			} elseif ($f == "-") {
				$ret->type = 1;
				$ret->classname = "Class";
				$ret->func = "SetNil";
				$ret->isVoid = 0;
				$ret->CallingConv = "pascal";
				$ret->params = array((object) array("modeIn"=> false));
				return $ret;
			}
			$f = explode("|",$f);
			$ret->type = 1;
			$ret->classname = $f[0];
			$ret->func = $f[1];
			if (substr($ret->func,-1) == "@") {
				$ret->isProperty = true;
				$ret->func = substr($ret->func,0,-1);
			} else $ret->isProperty = false;
			try {
				$restlen = strlen($f[2]);
			} catch (Exception $e) {
				var_dump($f);
				throw $e;
			}
			$ret->CallingConv = current(unpack('C',substr($f[2],$offset++,1)));
			switch ($ret->CallingConv) {
				case 0:
					$ret->CallingConv = "register";
					break;
				case 1:
					$ret->CallingConv = "pascal";
					break;
				case 2:
					$ret->CallingConv = "cdecl";
					break;
				case 3:
					$ret->CallingConv = "stdcall";
					break;
				default:
					throw new Exception("Unhandled calling convention ".$ret->CallingConv);
			}
			$ret->isVoid = !( (bool) ( current(unpack('C',substr($f[2],$offset++,1))) ) );
			$f = $f[2];
		} else {
			$restlen = strlen($f);
			$ret->type = 2;
			$ret->isVoid = !( (bool) ( current(unpack('C',substr($f,$offset++,1))) ) );
		}
		$ret->params = array();
		while ($offset < $restlen) {
			$p = current(unpack('C',substr($f,$offset++,1)));
			$ret->params[] = (object) array("modeIn"=> (bool)$p);
			$offset++;
		}
		return $ret;
	}
}