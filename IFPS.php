<?php

// IFPS: IFPS compiled file loader / structure.
require_once('DebugConfig.php');
require_once('DebugMode.php');
require_once('IFPSGlobals.php');
require_once('IFPSTypes.php');
require_once('IFPSOpcodes.php');

class IFPS {
	public $header;
	public $types = array();
	public $funcs = array();
	public $vars = array('globals'=>array(),'exported'=>array());

	const LowestSupportedVersion = 12;
	const HighestSupportedVersion = 23;

	private function __construct() { }

	public static function LoadFile($file) {
		return self::Load(file_get_contents($file));
	}

	public static function Load($data) {
		$origdata = $data;
		$obj = new static();
		// get the header
		$obj->LoadHeader($data);
		$data = substr($data,28);
		if (DEBUG) file_put_contents("CodeCurrParse.bin",$data);
		// load types
		$data = substr($data,$obj->LoadTypes($data));
		if (DEBUG) file_put_contents("CodeCurrParse.bin",$data);
		// load functions
		$data = substr($data,$obj->LoadFuncs($data,$origdata));
		if (DEBUG) file_put_contents("CodeCurrParse.bin",$data);
		// load global / exported vars
		$obj->vars = (object)$obj->vars;
		$data = substr($data,$obj->LoadVars($data));
		if (DEBUG) file_put_contents("CodeCurrParse.bin",$data);
		if (($obj->header->funcs < $obj->header->entrypoint) && ($obj->header->entrypoint != 0xffffffff)) {
			// invalid entrypoint
			// this is a hard fail in the original loader, I don't see an issue here?
		}
		return $obj;
	}

	private function LoadHeader($data) {
		if (strlen($data) < 28) throw new Exception("Reached end of file");
		$header = (object) unpack("Vmagic/Vversion/Vtypes/Vfuncs/Vvars/Ventrypoint/Vimportsize",$data);
		$header->magic = substr($data,0,4);
		if ($header->magic != "IFPS") {
			throw new Exception("Got incorrect magic: 0x".$hexmagic);
		}
		if (($header->version < self::LowestSupportedVersion) || ($header->version > self::HighestSupportedVersion)) {
			throw new Exception("This IFPS file uses unsupported version ".$header->version);
		}
		$this->header = $header;
	}

	private function LoadTypes($data) {
		$offset = 0;
		$datalen = strlen($data);
		for ($i = 0; $i < $this->header->types; $i++) {
			if (DEBUG) echo "[TYPE] Offset: 0x".dechex($offset)."\r\n";
			if ($datalen < $offset + 1) throw new Exception("Reached end of file");
			$basetype = current(unpack('C',substr($data,$offset,1)));
			$offset++;
			$fe = false;
			if ($basetype & 0x80) {
				$fe = true;
				$basetype -= 0x80;
			}
			switch ($basetype) {
				case IFPSTypes::S64:
				case IFPSTypes::U8:
				case IFPSTypes::S8:
				case IFPSTypes::U16:
				case IFPSTypes::S16:
				case IFPSTypes::U32:
				case IFPSTypes::S32:
				case IFPSTypes::Single:
				case IFPSTypes::Double:
				case IFPSTypes::Currency:
				case IFPSTypes::Extended:
				case IFPSTypes::String:
				case IFPSTypes::Pointer:
				case IFPSTypes::PChar:
				case IFPSTypes::Variant:
				case IFPSTypes::Char:
				case IFPSTypes::UnicodeString:
				case IFPSTypes::WideString:
				case IFPSTypes::WideChar:
					$curr = (object) array("BaseType" => $basetype);
					break;
				case IFPSTypes::_Class:
					$curr = (object) array("BaseType" => $basetype);
					$d = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
					if ($d > 255) throw new Exception("Obtained unexpected data: expected <= 0xff, got 0x".dechex($d));
					else if ($datalen < $offset + $d) throw new Exception("Reached end of file");
					$curr->FCN = substr($data,$offset,$d);
					$offset += $d;
					break;
				case IFPSTypes::ProcPtr:
					$curr = (object) array("BaseType" => $basetype);
					$d = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
					if ($d > 255) throw new Exception("Obtained unexpected data: expected <= 0xff, got 0x".dechex($d));
					else if ($datalen < $offset + $d) throw new Exception("Reached end of file");
					$curr->FParamInfo = substr($data,$offset,$d);
					$offset += $d;
					break;
				case IFPSTypes::Interface:
					$curr = (object) array("BaseType" => $basetype);
					if ($datalen < $offset + 16) throw new Exception("Reached end of file");
					$guid = (object) unpack("VD1/vD2/vD3",substr($data,$offset,8));
					$offset += 8;
					$guid->D4 = array();
					for ($gi = 0; $gi < 8; $gi++) {
						$guid->D4[] = current(unpack('C',substr($data,$offset,1)));
						$offset++;
					}
					$curr->FGUID = $guid;
					break;
				case IFPSTypes::Set:
					$curr = (object) array("BaseType" => $basetype);
					$d = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
					if ($d > 256) throw new Exception("Type mismatch: expected <= 0x100, got 0x".dechex($d));
					$curr->aBitSize = $d;
					$curr->aByteSize = $d >> 3;
					if ($d & 7) $curr->aByteSize++;
					break;
				case IFPSTypes::StaticArray:
					$curr = (object) array("BaseType" => $basetype);
					$d = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
					if ($d >= count($this->types)) throw new Exception("Type mismatch; type offset greater than currently known about types");
					$curr->ArrayType = $this->types[$d];
					$d = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
					if ($d > 0x3fffffff) /* 0xffffffff / 4 */ throw new Exception("Reached end of file");
					$curr->Size = $d;
					if ($this->header->version > 22) {
						$d = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
						$curr->StartOffset = $d;
					}
					break;
				case IFPSTypes::Array:
					$curr = (object) array("BaseType" => $basetype);
					$d = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
					if ($d >= count($this->types)) throw new Exception("Type offset out of range");
					$curr->ArrayType = $this->types[$d];
					break;
				case IFPSTypes::Record;
					$curr = (object) array("BaseType" => $basetype);
					$curr->FFieldTypes = array();
					$fieldtypenum = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
					for ($d = 0; $d < $fieldtypenum; $d++) {
						$l2 = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
						if ($l2 >= count($this->types)) throw new Exception("Type offset out of range");
						$curr->FFieldTypes[] = $this->types[$l2];
					}
					break;
				default:
					throw new Exception("Invalid type 0x".dechex($basetype)." (offset: 0x".dechex(28 + $offset).")");
					break;
			}
			if ($fe) {
				$d = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
				if ($d > 0x40000000) throw new Exception("Invalid type");
				else if ($datalen < $offset + $d) throw new Exception("Reached end of file");
				$curr->ExportName = substr($data,$offset,$d);
				$offset += $d;
				$curr->ExportNameHash = IFPSGlobals::MakeHash($curr->ExportName);
			}
			switch ($basetype) {
				case IFPSTypes::Variant:
					$curr->FRealSize = 16; // sizeof(Variant)
					break;
				case IFPSTypes::Char:
				case IFPSTypes::S8:
				case IFPSTypes::U8:
					$curr->FRealSize = 1;
					break;
				case IFPSTypes::WideChar:
				case IFPSTypes::S16:
				case IFPSTypes::U16:
					$curr->FRealSize = 2;
					break;
				case IFPSTypes::WideString:
				case IFPSTypes::UnicodeString:
				case IFPSTypes::Interface:
				case IFPSTypes::_Class:
				case IFPSTypes::PChar:
				case IFPSTypes::String:
					$curr->FRealSize = 4;
					break;
				case IFPSTypes::Single:
				case IFPSTypes::S32:
				case IFPSTypes::U32:
					$curr->FRealSize = 4;
					break;
				case IFPSTypes::ProcPtr:
					$curr->FRealSize = 2*4 + 4;
					break;
				case IFPSTypes::Currency:
					$curr->FRealSize = 8; // sizeof(Currency)
					break;
				case IFPSTypes::Pointer:
					$curr->FRealSize = 2*4 + 4;
					break;
				case IFPSTypes::Double:
				case IFPSTypes::S64:
					$curr->FRealSize = 8;
					break;
				case IFPSTypes::Extended:
					$curr->FRealSize = 10; // sizeof(Extended)
					break;
				case IFPSTypes::ReturnAddress:
					$curr->FRealSize = 28; // sizeof(TBTReturnAddress)
					break;
				default:
					$curr->FRealSize = 0;
					break;
			}
			$this->types[] = $curr;
			if ($this->header->version >= 21) {
				// load attributes
				$curr->Attributes = $this->LoadAttributes($data,$datalen,$offset);
				$this->types[$i] = $curr;
			}
		}
		
		return $offset;
	}
	
	private function LoadAttributes($data,$datalen,&$offset) {
		$ret = array();
		$attribcount = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
		for ($ai = 0; $ai < $attribcount; $ai++) {
			if (DEBUG) echo "[ATT] Count: ".$attribcount." - Offset: 0x".dechex($offset)."\r\n";
			$namelen = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
			if ($datalen < $offset + $namelen) throw new Exception("Reached end of file");
			$name = substr($data,$offset,$namelen);
			$offset += $namelen;
			$fieldcount = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
			$att = (object)array();
			$att->AttribType = $name;
			$att->AttribTypeHash = IFPSGlobals::MakeHash($name);
			for ($fi = 0; $fi < $fieldcount; $fi++) {
				$typeno = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
				if ($typeno > count($this->types)) throw new Exception("Type offset out of range");
				$varp = $this->types[$typeno];
				switch ($varp->BaseType) {
					case IFPSTypes::Set:
						if ($datalen < $offset + $varp->aByteSize) throw new Exception("Reached end of file");
						$att->Data = substr($data,$offst,$varp->aByteSize);
						$offset += $varp->aByteSize;
						break;
					case IFPSTypes::S8:
					case IFPSTypes::Char:
					case IFPSTypes::U8:
						if ($datalen < $offset + 1) throw new Exception("Reached end of file");
						switch ($varp->BaseType) {
							case IFPSTypes::S8:
								$att->Data = current(unpack('c',substr($data,$offset,1)));
								break;
							case IFPSTypes::U8:
								$att->Data = current(unpack('C',substr($data,$offset,1)));
								break;
							case IFPSTypes::Char:
								$att->Data = substr($data,$offset,1);
								break;
						}
						$offset += 1;
						break;
					case IFPSTypes::S16:
					case IFPSTypes::WideChar:
					case IFPSTypes::U16:
						if ($datalen < $offset + 2) throw new Exception("Reached end of file");
						switch ($varp->BaseType) {
							case IFPSTypes::S16:
								$att->Data = current(unpack('v',substr($data,$offset,2)));
								// no pack() format char for signed little endian int16, so convert from unsigned to signed ourselves
								if ($att->Data >= 0x8000) $att->Data -= 0x10000;
								break;
							case IFPSTypes::U16:
								$att->Data = current(unpack('v',substr($data,$offset,2)));
								break;
							case IFPSTypes::WideChar:
								$att->Data = mb_convert_encoding(substr($data,$offset,2),'utf-8','utf-16le');
								break;
						}
						$offset += 2;
						break;
					case IFPSTypes::S32:
					case IFPSTypes::U32:
						$att->Data = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
						if ($varp->BaseType == IFPSTypes::S32) {
							// no pack() format char for signed little endian int32, so convert from unsigned to signed ourselves
							if ($att->Data >= 0x80000000) $att->Data -= 0x100000000;
						}
						break;
					case IFPSTypes::ProcPtr:
						$att->Data = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
						if ($att->Data == 0) {
							$att->Ptr = $att->Self = null;
						}
						break;
					case IFPSTypes::S64:
						if ($datalen < $offset + 8) throw new Exception("Reached end of file");
						$att->Data = current(unpack('P',substr($data,$offset,8)));
						if ($att->Data >= 0x8000000000000000) $att->Data -= 0x10000000000000000;
						$offset += 8;
						break;
					case IFPSTypes::Single:
						$att->Data = IFPSGlobals::hex2float(str_pad(dechex(IFPSGlobals::ReadUInt32($data,$datalen,$offset)),8,'0',STR_PAD_LEFT));
						break;
					case IFPSTypes::Double:
						if ($datalen < $offset + 8) throw new Exception("Reached end of file");
						if (IFPSGlobals::isLittleEndian()) {
							$att->Data = current(unpack('d',substr($data,$offset,8)));
						} else {
							// swap endianness, yayhaxx
							$att->Data = current(unpack('d',pack('Q',current(unpack('P',substr($data,$offset,8))))));
						}
						$offset += 8;
						break;
					case IFPSTypes::Extended:
						// this probably won't work, but still.
						if ($datalen < $offset + 10) throw new Exception("Reached end of file");
						$att->Data = IFPSGlobals::hex_extended2float(current(unpack('H*',substr($data,$offset,10))));
						$offset += 10;
						break;
					case IFPSTypes::Currency:
						if ($datalen < $offset + 8) throw new Exception("Reached end of file");
						$att->Data = current(unpack('P',substr($data,$offset,8))) / 10000;
						$offset += 8;
						break;
					case IFPSTypes::PChar:
					case IFPSTypes::String:
						$namelen = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
						if ($datalen < $offset + $namelen) throw new Exception("Reached end of file");
						$att->Data = substr($data,$offset,$namelen);
						$offset += $namelen;
						break;
					case IFPSTypes::WideString:
					case IFPSTypes::UnicodeString:
						$namelen = IFPSGlobals::ReadUInt32($data,$datalen,$offset) * 2;
						if ($datalen < $offset + $namelen) throw new Exception("Reached end of file");
						$att->Data = mb_convert_encoding(substr($data,$offset,$namelen),'utf-8','utf-16le');
						$offset += $namelen;
						break;
					default:
						throw new Exception("Invalid type");
						break;
				}
				$ret[] = $att;
			}
		}
		return $ret;
	}
	
	private function LoadFuncs($data,$origdata) {
		$offset = 0;
		$datalen = strlen($data);
		$origdatalen = strlen($origdata);
		for ($i = 0; $i < $this->header->funcs; $i++) {
			if (DEBUG) echo "[FUNC] Offset: 0x".dechex($offset)."\r\n";
			if ($datalen < $offset + 1) throw new Exception("Reached end of file");
			$curr = (object) array('Flags' => current(unpack('C',substr($data,$offset,1))) );
			$offset++;
			if ($curr->Flags & 1) {
				// external (imported)
				if ($datalen < $offset + 1) throw new Exception("Reached end of file");
				$namelen = current(unpack('C',substr($data,$offset,1)));
				$offset++;
				if ($datalen < $offset + $namelen) throw new Exception("Reached end of file");
				$curr->Name = substr($data,$offset,$namelen);
				$offset += $namelen;
				if (($curr->Flags & 3) == 3) {
					$l2 = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
					if ($datalen < $offset + $l2) throw new Exception("Reached end of file");
					$curr->FDecl = IFPSGlobals::ParseFDecl(substr($data,$offset,$l2));
					$offset += $l2;
				}
			} else {
				// VM bytecode function
				$l2 = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
				$l3 = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
				if (($origdatalen < $l2 + $l3) || (!$l3)) throw new Exception("Reached end of file");
				$curr->FData = substr($origdata,$l2,$l3);
				$curr->FLength = $l3;
				IFPSGlobals::$isVoid = false;
				if ($curr->Flags & 2) {
					// exported function
					$l3 = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
					if ($datalen < $offset + $l3) throw new Exception("Reached end of file");
					$curr->FExportName = substr($data,$offset,$l3);
					$offset += $l3;
					$l3 = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
					if ($datalen < $offset + $l3) throw new Exception("Reached end of file");
					$curr->FExportDecl = $this->ParseExportDecl(substr($data,$offset,$l3));
					IFPSGlobals::$isVoid = $curr->FExportDecl->isVoid;
					$offset += $l3;
					$curr->FExportNameHash = IFPSGlobals::MakeHash($curr->FExportName);
				}
				$curr->FData = $this->ParseBytecode($curr->FData);
			}
			if ($curr->Flags & 4) {
				$curr->Attributes = $this->LoadAttributes($data,$datalen,$offset);
			}
			$this->funcs[] = $curr;
		}
		return $offset;
	}
	
	private function LoadVars($data) {
		$offset = 0;
		$datalen = strlen($data);
		for ($i = 0; $i < $this->header->vars; $i++) {
			if (DEBUG) echo "[VAR] Offset: 0x".dechex($offset)."\r\n";
			if ($datalen < $offset + 5) throw new Exception("Reached end of file");
			$rec = (object) unpack("VTypeNo/CFlags",substr($data,$offset,5));
			$offset += 5;
			if (($rec->TypeNo > $this->header->types) || (!array_key_exists($rec->TypeNo,$this->types))) throw new Exception("Invalid type");
			$this->vars->globals[] = (object) array('type'=>$this->types[$rec->TypeNo]);
			if ($rec->Flags & 1) {
				// exported
				$n = IFPSGlobals::ReadUInt32($data,$datalen,$offset);
				$e = (object) array();
				if ($datalen < $offset + $n) throw new Exception("Reached end of file");
				$e->FName = substr($data,$offset,$n);
				$offset += $n;
				$e->FNameHash = IFPSGlobals::MakeHash($e->FName);
				$e->FVarNo = $i;
				$this->vars->exported[] = $e;
			}
		}
		return $offset;
	}
	
	private function ParseOperand($bc,$bclen,&$offset) {
		if ($bclen < $offset + 1) return array();
		$vartype = current(unpack('C',substr($bc,$offset++,1)));
		switch ($vartype) {
			case 0:
				return (object)array("otype"=>$vartype,"var"=>IFPSGlobals::ParseVar(IFPSGlobals::ReadUInt32($bc,$bclen,$offset)));
			case 1:
				$type = IFPSGlobals::ReadUInt32($bc,$bclen,$offset);
				if (!array_key_exists($type,$this->types)) throw new Exception("Type offset out of range");
				$type = $this->types[$type];
				$ret = (object)array("otype"=>$vartype,"type"=>$type);
				switch ($type->BaseType) {
					case IFPSTypes::Set:
						if ($bclen < $offset + $type->aByteSize) throw new Exception("Reached end of file");
						$data = substr($bc,$offset,$type->aByteSize);
						$offset += $type->aByteSize;
						$ret->value = $data;
						return $ret;
					case IFPSTypes::S8:
					case IFPSTypes::Char:
					case IFPSTypes::U8:
						if ($bclen < $offset + 1) throw new Exception("Reached end of file");
						switch ($type->BaseType) {
							case IFPSTypes::S8:
								$ret->value = current(unpack('c',substr($bc,$offset,1)));
								break;
							case IFPSTypes::U8:
								$ret->value = current(unpack('C',substr($bc,$offset,1)));
								break;
							case IFPSTypes::Char:
								$ret->value = substr($bc,$offset,1);
								break;
						}
						$offset += 1;
						return $ret;
					case IFPSTypes::S16:
					case IFPSTypes::WideChar:
					case IFPSTypes::U16:
						if ($bclen < $offset + 2) throw new Exception("Reached end of file");
						switch ($type->BaseType) {
							case IFPSTypes::S16:
								$ret->value = current(unpack('v',substr($bc,$offset,2)));
								// no pack() format char for signed little endian int16, so convert from unsigned to signed ourselves
								if ($ret->value >= 0x8000) $ret->value -= 0x10000;
								break;
							case IFPSTypes::U16:
								$ret->value = current(unpack('v',substr($bc,$offset,2)));
								break;
							case IFPSTypes::WideChar:
								$ret->value = mb_convert_encoding(substr($bc,$offset,2),'utf-8','utf-16le');
								break;
						}
						$offset += 2;
						return $ret;
					case IFPSTypes::S32:
					case IFPSTypes::U32:
						$ret->value = IFPSGlobals::ReadUInt32($bc,$bclen,$offset);
						if ($type->BaseType == IFPSTypes::S32) {
							// no pack() format char for signed little endian int32, so convert from unsigned to signed ourselves
							if ($ret->value >= 0x80000000) $ret->value -= 0x100000000;
						}
						return $ret;
					case IFPSTypes::ProcPtr:
						$ret->value = IFPSGlobals::ReadUInt32($bc,$bclen,$offset);
						if ($ret->value == 0) {
							$att->Ptr = $att->Self = null;
						}
						return $ret;
					case IFPSTypes::S64:
						if ($bclen < $offset + 8) throw new Exception("Reached end of file");
						$ret->value = current(unpack('P',substr($bc,$offset,8)));
						if ($ret->value >= 0x8000000000000000) $ret->value -= 0x10000000000000000;
						$offset += 8;
						return $ret;
					case IFPSTypes::Single:
						$ret->value = IFPSGlobals::hex2float(str_pad(dechex(IFPSGlobals::ReadUInt32($bc,$bclen,$offset)),8,'0',STR_PAD_LEFT));
						return $ret;
					case IFPSTypes::Double:
						if ($bclen < $offset + 8) throw new Exception("Reached end of file");
						if (IFPSGlobals::isLittleEndian()) {
							$ret->value = current(unpack('d',substr($bc,$offset,8)));
						} else {
							// swap endianness, yayhaxx
							$ret->value = current(unpack('d',pack('Q',current(unpack('P',substr($bc,$offset,8))))));
						}
						$offset += 8;
						return $ret;
					case IFPSTypes::Extended:
						// BUGBUG: this does not work, and probably there's no way to make it work.
						if ($bclen < $offset + 10) throw new Exception("Reached end of file");
						$ret->value = IFPSGlobals::hex_extended2float(current(unpack('H*',substr($bc,$offset,10))));
						$offset += 10;
						return $ret;
					case IFPSTypes::Currency:
						if ($bclen < $offset + 8) throw new Exception("Reached end of file");
						$ret->value = current(unpack('P',substr($bc,$offset,8))) / 10000;
						$offset += 8;
						return $ret;
					case IFPSTypes::PChar:
					case IFPSTypes::String:
						$namelen = IFPSGlobals::ReadUInt32($bc,$bclen,$offset);
						if ($bclen < $offset + $namelen) throw new Exception("Reached end of file");
						$ret->value = substr($bc,$offset,$namelen);
						$offset += $namelen;
						return $ret;
					case IFPSTypes::WideString:
					case IFPSTypes::UnicodeString:
						$namelen = IFPSGlobals::ReadUInt32($bc,$bclen,$offset) * 2;
						if ($bclen < $offset + $namelen) throw new Exception("Reached end of file");
						$ret->value = mb_convert_encoding(substr($bc,$offset,$namelen),'utf-8','utf-16le');
						$offset += $namelen;
						return $ret;
					default:
						throw new Exception("Invalid type");
						break;
				}
			case 2:
				return (object)array("otype"=>$vartype,"var"=>IFPSGlobals::ParseVar(IFPSGlobals::ReadUInt32($bc,$bclen,$offset)),"index"=>IFPSGlobals::ReadUInt32($bc,$bclen,$offset));
			case 3:
				return (object)array("otype"=>$vartype,"var"=>IFPSGlobals::ParseVar(IFPSGlobals::ReadUInt32($bc,$bclen,$offset)),"index"=>IFPSGlobals::ParseVar(IFPSGlobals::ReadUInt32($bc,$bclen,$offset)));
			default:
				throw new Exception("Unhandled operand");
		}
	}
	
	private function ParseBytecode($bc) {
		$ret = array();
		$offset = 0;
		$bclen = strlen($bc);
		$StackCount = 0;
		if (DEBUG) file_put_contents("curr_bytecode.bin",$bc);
		do {
			if (DEBUG) echo "[Bytecode] Offset: 0x".dechex($offset)."\r\n";
			if ($bclen < $offset + 1) throw new Exception("Reached end of bytecode");
			$curr = (object) array( "offset" => $offset, "opcode" => current(unpack('C',substr($bc,$offset,1))), "operands"=>array(), "jumptarget"=>false );
			$offset++;
			switch ($curr->opcode) {
				case IFPSOpcodes::Assign:
					for ($i = 0; $i < 2; $i++) {
						$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					}
					break;
				case IFPSOpcodes::Calculate:
					if ($bclen < $offset + 1) throw new Exception("Reached end of bytecode");
					$cop = current(unpack('C',substr($bc,$offset++,1)));
					$cops = array("+","-","*","/","%","<<",">>","&","|","^");
					if (!array_key_exists($cop,$cops)) throw new Exception("Unhandled Calculate operand");
					$cop = $cops[$cop];
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					$curr->operands[] = $cop;
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::Push:
					$StackCount++;
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::PushVar:
					$StackCount++;
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::Pop:
					$StackCount--;
					break;
				case IFPSOpcodes::Call:
					$curr->operands[] = IFPSGlobals::ReadUInt32($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::Jump:
					$jumploc = IFPSGlobals::ReadUInt32($bc,$bclen,$offset);
					if ($jumploc >= 0x80000000) $jumploc -= 0x100000000;
					$curr->operands[] = $jumploc + $offset;
					break;
				case IFPSOpcodes::JumpTrue:
					$jumploc = IFPSGlobals::ReadUInt32($bc,$bclen,$offset);
					if ($jumploc >= 0x80000000) $jumploc -= 0x100000000;
					$op2 = $this->ParseOperand($bc,$bclen,$offset);
					$curr->operands[] = $jumploc + $offset;
					$curr->operands[] = $op2;
					break;
				case IFPSOpcodes::JumpFalse:
					$jumploc = IFPSGlobals::ReadUInt32($bc,$bclen,$offset);
					if ($jumploc >= 0x80000000) $jumploc -= 0x100000000;
					$op2 = $this->ParseOperand($bc,$bclen,$offset);
					$curr->operands[] = $jumploc + $offset;
					$curr->operands[] = $op2;
					break;
				case IFPSOpcodes::Ret:
					break;
				case IFPSOpcodes::SetStackType:
					$curr->operands[] = IFPSGlobals::ParseVar(IFPSGlobals::ReadUInt32($bc,$bclen,$offset));
					$curr->operands[] = IFPSGlobals::ReadUInt32($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::PushType:
					$StackCount++;
					$curr->operands[] = IFPSGlobals::ReadUInt32($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::Compare:
					if ($bclen < $offset + 1) throw new Exception("Reached end of bytecode");
					$cop = current(unpack('C',substr($bc,$offset++,1)));
					$cops = array(">=","<=",">","<","!=","==");
					if (!array_key_exists($cop,$cops)) throw new Exception("Unhandled Compare operand");
					$cop = $cops[$cop];
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					$curr->operands[] = $cop;
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::CallVar:
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::SetPtr:
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::LogicalNot:
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::Neg:
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::SetFlag:
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					if ($bclen < $offset + 1) throw new Exception("Reached end of bytecode");
					$curr->operands[] = current(unpack('C',substr($bc,$offset++,1)));
					break;
				case IFPSOpcodes::JumpFlag:
					$jumploc = IFPSGlobals::ReadUInt32($bc,$bclen,$offset);
					if ($jumploc >= 0x80000000) $jumploc -= 0x100000000;
					$curr->operands[] = $jumploc + $offset;
					break;
				case IFPSOpcodes::PushEH:
					for ($i = 0; $i < 4; $i++)
						$curr->operands[] = IFPSGlobals::ReadUInt32($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::PopEH:
					if ($bclen < $offset + 1) throw new Exception("Reached end of bytecode");
					$curr->operands[] = current(unpack('C',substr($bc,$offset++,1)));
					break;
				case IFPSOpcodes::Not:
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::SetCopyPointer:
					for ($i = 0; $i < 2; $i++) {
						$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					}
					break;
				case IFPSOpcodes::Inc:
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::Dec:
					$curr->operands[] = $this->ParseOperand($bc,$bclen,$offset);
					break;
				case IFPSOpcodes::PopJump:
					$StackCount--;
					$jumploc = IFPSGlobals::ReadUInt32($bc,$bclen,$offset);
					if ($jumploc >= 0x80000000) $jumploc -= 0x100000000;
					$curr->operands[] = $jumploc + $offset;
					break;
				case IFPSOpcodes::PopPopJump:
					$StackCount -= 2;
					$jumploc = IFPSGlobals::ReadUInt32($bc,$bclen,$offset);
					if ($jumploc >= 0x80000000) $jumploc -= 0x100000000;
					$curr->operands[] = $jumploc + $offset;
					break;
				case IFPSOpcodes::Nop:
					break;
				default:
					var_dump($ret);
					throw new Exception("Unknown opcode: 0x".dechex($curr->opcode));
			}
			$curr->stackcount = $StackCount;
			$ret[] = $curr;
		} while ($offset < $bclen);
		// walk the bytecode to resolve jump targets
		foreach ($ret as $inst) {
			if (in_array($inst->opcode,array(IFPSOpcodes::Jump,IFPSOpcodes::JumpTrue,IFPSOpcodes::JumpFalse,IFPSOpcodes::JumpFlag,IFPSOpcodes::PopJump,IFPSOpcodes::PopPopJump))) {
				$resolved = false;
				foreach ($ret as &$target) {
					if ($target->offset == $inst->operands[0]) {
						$target->jumptarget = $resolved = true;
						break;
					}
				}
				if (!$resolved) throw new Exception("Couldn't resolve jump target - location: 0x".dechex($inst->operands[0]));
			}
		}
		return $ret;
	}
	
	private function ParseExportDecl($edecl) {
		$edecl = explode(" ",$edecl);
		
		$ret = (object) array();
		$rtype = (int) array_shift($edecl);
		if ($rtype == -1) $ret->isVoid = true;
		else {
			$ret->ReturnType = $this->types[$rtype];
			$ret->isVoid = false;
		}
		$ret->params = array();
		foreach ($edecl as $param) {
			$pmode = substr($param,0,1);
			$paramtype = (int) substr($param,1);
			$p = (object) array("modeIn" => ($pmode === "@"), "type" => $this->types[$paramtype]);
			$ret->params[] = $p;
		}
		return $ret;
	}
}