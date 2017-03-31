<?php

// IFPSDisassembler: disassembles a IFPS object.
require_once('IFPS.php');

class IFPSDisassembler {
	private $ifps;

	public function __construct($IFPSObj) {
		if (!($IFPSObj instanceof IFPS)) throw new Exception("Passed object is not an instance of IFPS");
		$this->ifps = $IFPSObj;
	}

	public function DumpType($t) {
		switch ($t->BaseType) {
			case IFPSTypes::S64:
				return "S64";
			case IFPSTypes::U8:
				return "U8";
			case IFPSTypes::S8:
				return "S8";
			case IFPSTypes::U16:
				return "U16";
			case IFPSTypes::S16:
				return "S16";
			case IFPSTypes::U32:
				return "U32";
			case IFPSTypes::S32:
				return "S32";
			case IFPSTypes::Single:
				return "Single";
			case IFPSTypes::Double:
				return "Double";
			case IFPSTypes::Currency:
				return "Currency";
			case IFPSTypes::Extended:
				return "Extended";
			case IFPSTypes::String:
				return "String";
			case IFPSTypes::Pointer:
				return "Pointer";
			case IFPSTypes::PChar:
				return "PChar";
			case IFPSTypes::Variant:
				return "Variant";
			case IFPSTypes::Char:
				return "Char";
			case IFPSTypes::UnicodeString:
				return "UnicodeString";
			case IFPSTypes::WideString:
				return "WideString";
			case IFPSTypes::WideChar:
				return "WideChar";
			case IFPSTypes::_Class:
				return "Class";
			case IFPSTypes::ProcPtr:
				return "ProcPtr";
			case IFPSTypes::_Interface:
				return "Interface";
			case IFPSTypes::Set:
				return "Set";
			case IFPSTypes::StaticArray:
				return $this->DumpType($t->ArrayType)."[".$t->Size."]";
			case IFPSTypes::_Array:
				return $this->DumpType($t->ArrayType)."[]";
			case IFPSTypes::Record;
				$ret = "Record <";
				foreach ($t->FFieldTypes as $ft) {
					$ret .= $this->DumpType($ft).",";
				}
				return substr($ret,0,-1).">";
			default:
				return "Unknown type 0x".dechex($t->BaseType);
		}
	}

	public function Disassemble() {
		return implode("\r\n",array($this->DumpTypes(),$this->DumpVars(),$this->DumpDisasm()));
	}

	public function DumpTypes() {
		$ret = "";
		$i = 0;
		foreach ($this->ifps->types as $t) {
			$ret .= "Types[".($i++)."] = ";
			if (property_exists($t,"ExportName")) $ret .= $t->ExportName." = ";
			$ret .= $this->DumpType($t)."\r\n";
		}
		return $ret;
	}

	public function DumpVars() {
		$ret = "";
		$i = 0;
		foreach ($this->ifps->vars->globals as $v) {
			$ret .= "Vars[".($i++)."].Type = ";
			if (property_exists($v->type,"ExportName")) $ret .= $v->type->ExportName." = ";
			$ret .= $this->DumpType($v->type)."\r\n";
		}
		return $ret;
	}

	public function DumpOperand($o) {
		if (!is_object($o)) {
			if (is_integer($o)) return "0x".dechex($o);
			return $o;
		}
		switch ($o->otype) {
			case 0:
				return $o->var->type.($o->var->index != -1 ? $o->var->index : '');
			case 1:
				$ret = "( ".$this->DumpType($o->type)." ";
				switch ($o->type->BaseType) {
					case IFPSTypes::String:
					case IFPSTypes::UnicodeString:
					case IFPSTypes::WideString:
					case IFPSTypes::PChar:
						$ret .= '"'.str_replace('"','\"',$o->value).'"';
						break;
					case IFPSTypes::Char:
					case IFPSTypes::WideChar:
						$ret .= "'".str_replace("'","\\'",$o->value)."'";
						break;
					case IFPSTypes::ProcPtr:
						if (!array_key_exists($o->value,$this->ifps->funcs)) $ret .= "func_".dechex($o->value);
						else {
							$f = $this->ifps->funcs[$o->value];
							if ($f->Flags & 1) {
								if ($f->Flags & 2) {
									switch ($f->FDecl->type) {
										case 0:
											$ret .= $f->FDecl->dll."!".$f->FDecl->func;
											break;
										case 1:
											$ret .= $f->FDecl->classname."->".$f->FDecl->func;
											break;
										case 2:
											if ($f->Name != "") $ret .= $f->Name;
											else $ret .= "func_".dechex($o->value);
											break;
									}
								} else {
									if ($f->Name != "") $ret .= $f->Name;
									else $ret .= "func_".dechex($o->value);
								}
							} else {
								if ($f->Flags & 2) $ret .= $f->FExportName;
								else $ret .= "func_".dechex($o->value);
							}
						}
						break;
					default:
						$ret .= $o->value;
						break;
				}
				$ret .= " )";
				return $ret;
			case 2:
				return $o->var->type.($o->var->index != -1 ? $o->var->index : '')."[".$o->index."]";
			case 3:
				return $o->var->type.($o->var->index != -1 ? $o->var->index : '')."[".$o->index->type.($o->index->index != -1 ? $o->index->index : '')."]";
			default:
				return "UnknownOType".$o->otype;
		}
	}

	public function DumpOperands($o,$sep = ", ") {
		$ret = array();
		foreach ($o as $op) $ret[] = $this->DumpOperand($op);
		return implode($sep,$ret);
	}

	public function DisasmFunc($bytecode) {
		$ret = "";
		foreach ($bytecode as $inst) {
			if ($inst->jumptarget) $ret .= "\tloc_".dechex($inst->offset).":\r\n";
			$ret .= "\t\t";
			switch ($inst->opcode) {
				case IFPSOpcodes::Assign:
					$ret .= "assign ".$this->DumpOperands($inst->operands);
					break;
				case IFPSOpcodes::Calculate:
					$ret .= "calculate ".$this->DumpOperands($inst->operands," ");
					break;
				case IFPSOpcodes::Push:
					$ret .= "push ".$this->DumpOperands($inst->operands);
					break;
				case IFPSOpcodes::PushVar:
					$ret .= "pushvar ".$this->DumpOperands($inst->operands);
					break;
				case IFPSOpcodes::Pop:
					$ret .= "pop";
					break;
				case IFPSOpcodes::Call:
					$ret .= "call ";
					if (!array_key_exists($inst->operands[0],$this->ifps->funcs)) $ret .= "func_".dechex($inst->operands[0]);
					else {
						$f = $this->ifps->funcs[$inst->operands[0]];
						if ($f->Flags & 1) {
							if ($f->Flags & 2) {
								switch ($f->FDecl->type) {
									case 0:
										$ret .= $f->FDecl->dll."!".$f->FDecl->func;
										break;
									case 1:
										$ret .= $f->FDecl->classname."->".$f->FDecl->func;
										break;
									case 2:
										if ($f->Name != "") $ret .= $f->Name;
										else $ret .= "func_".dechex($inst->operands[0]);
										break;
								}
							} else {
								if ($f->Name != "") $ret .= $f->Name;
								else $ret .= "func_".dechex($inst->operands[0]);
							}
						} else {
							if ($f->Flags & 2) $ret .= $f->FExportName;
							else $ret .= "func_".dechex($inst->operands[0]);
						}
					}
					break;
				case IFPSOpcodes::Jump:
					$ret .= "jump loc_".dechex($inst->operands[0]);
					break;
				case IFPSOpcodes::JumpTrue:
					$ret .= "jumptrue ".$this->DumpOperand($inst->operands[1]).", loc_".dechex($inst->operands[0]);
					break;
				case IFPSOpcodes::JumpFalse:
					$ret .= "jumpfalse ".$this->DumpOperand($inst->operands[1]).", loc_".dechex($inst->operands[0]);
					break;
				case IFPSOpcodes::Ret:
					$ret .= "ret";
					break;
				case IFPSOpcodes::SetStackType:
					$ret .= "setstacktype ".$inst->operands[0]->type.$inst->operands[0].index." ".$inst->operands[1];
					break;
				case IFPSOpcodes::PushType:
					$ret .= "pushtype ";
					if (!array_key_exists($inst->operands[0],$this->ifps->types)) $ret .= "type_".dechex($inst->operands[0]);
					else {
						$t = $this->ifps->types[$inst->operands[0]];
						if (property_exists($t,"ExportName")) $ret .= $t->ExportName;
						else $ret .= $this->DumpType($t);
					}
					break;
				case IFPSOpcodes::Compare:
					$ret .= "compare ".$this->DumpOperand($inst->operands[0]).
						", ".$this->DumpOperand($inst->operands[1]).
						" ".$inst->operands[2].
						" ";
					if ($inst->operands[2] == "is") {
						// if the comparison type is "is", then operand3 is a type, so dump it accordingly
						$oval = $inst->operands[3]->value;
						if (!array_key_exists($oval,$this->ifps->types)) $ret .= "type_".dechex($oval);
						else {
							$t = $this->ifps->types[$oval];
							if (property_exists($t,"ExportName")) $ret .= $t->ExportName;
							else $ret .= $this->DumpType($t);
						}
					} else {
						// otherwise, just dump the operand.
						$ret .= $this->DumpOperand($inst->operands[3]);
					}
					break;
				case IFPSOpcodes::CallVar:
					$ret .= "callvar ".$this->DumpOperands($inst->operands);
					break;
				case IFPSOpcodes::SetPtr:
					$ret .= "setptr ".$this->DumpOperands($inst->operands);
					break;
				case IFPSOpcodes::LogicalNot:
					$ret .= "logicalnot ".$this->DumpOperands($inst->operands);
					break;
				case IFPSOpcodes::Neg:
					$ret .= "neg ".$this->DumpOperands($inst->operands);
					break;
				case IFPSOpcodes::SetFlag:
					$ret .= "setflag ".($inst->operands[1]?"not ":"").$this->DumpOperand($inst->operands[0]);
					break;
				case IFPSOpcodes::JumpFlag:
					$ret .= "jumpflag loc_".dechex($inst->operands[0]);
					break;
				case IFPSOpcodes::PushEH:
					$ret .= "pusheh ".$this->DumpOperands($inst->operands);
					break;
				case IFPSOpcodes::PopEH:
					$ret .= "popeh ".$this->DumpOperands($inst->operands);
					break;
				case IFPSOpcodes::Not:
					$ret .= "not ".$this->DumpOperands($inst->operands);
					break;
				case IFPSOpcodes::SetCopyPointer:
					$ret .= "setcopypointer ".$this->DumpOperands($inst->operands);
					break;
				case IFPSOpcodes::Inc:
					$ret .= "inc ".$this->DumpOperands($inst->operands);
					break;
				case IFPSOpcodes::Dec:
					$ret .= "dec ".$this->DumpOperands($inst->operands);
					break;
				case IFPSOpcodes::PopJump:
					$ret .= "popjump loc_".dechex($inst->operands[0]);
					break;
				case IFPSOpcodes::PopPopJump:
					$ret .= "poppopjump loc_".dechex($inst->operands[0]);
					break;
				case IFPSOpcodes::Nop:
					$ret .= "nop";
					break;
				default:
					throw new Exception("Unknown opcode: 0x".dechex($inst->opcode));
			}
			if (in_array($inst->opcode,array(IFPSOpcodes::Push,IFPSOpcodes::PushVar,IFPSOpcodes::Pop,IFPSOpcodes::PushType,IFPSOpcodes::PopJump,IFPSOpcodes::PopPopJump)))
				$ret .= " ; StackCount = ".$inst->stackcount;
			$ret .= "\r\n";
		}
		return $ret;
	}

	public function DumpDisasm() {
		$ret = "";
		$i = 0;
		foreach ($this->ifps->funcs as $f) {
			$isVoid = false;
			$ret .= "Functions[".($i++)."] = ";
			if ($f->Flags & 1) {
				$ret .= "external ";
				if ($f->Flags & 2) {
					switch ($f->FDecl->type) {
						case 0:
							$ret .= $f->FDecl->CallingConv." ";
							if ($f->FDecl->DelayLoad) $ret .= "delayload ";
							if ($f->FDecl->LoadWithAlteredSearchPath) $ret .= "loadwithalteredsearchpath ";
							if ($f->FDecl->isVoid) $ret .= "void ";
							else $ret .= "returnsval ";
							$ret .= $f->FDecl->dll."!".$f->FDecl->func."(";
							$pi = 1;
							$args = "";
							foreach ($f->FDecl->params as $fp) {
								if ($fp->modeIn) $args .= "in ";
								else $args .= "out ";
								$args .= "Arg".($pi++).",";
							}
							$args = substr($args,0,-1);
							$ret .= $args.")\r\n";
							break;
						case 1:
							$ret .= $f->FDecl->CallingConv." ";
							if ($f->FDecl->isVoid) $ret .= "void ";
							else $ret .= "returnsval ";
							$ret .= $f->FDecl->classname."->".$f->FDecl->func."(";
							$pi = 1;
							$args = "";
							foreach ($f->FDecl->params as $fp) {
								if ($fp->modeIn) $args .= "in ";
								else $args .= "out ";
								$args .= "Arg".($pi++).",";
							}
							$args = substr($args,0,-1);
							$ret .= $args.")\r\n";
							break;
						case 2:
							if ($f->FDecl->isVoid) $ret .= "void ";
							else $ret .= "returnsval ";
							if ($f->Name != "") $ret .= $f->Name;
							else $ret .= "func_".dechex($inst->operands[0]);
							$pi = 1;
							$args = "";
							$ret .= '(';
							foreach ($f->FDecl->params as $fp) {
								if ($fp->modeIn) $args .= "in ";
								else $args .= "out ";
								$args .= "Arg".($pi++).",";
							}
							$args = substr($args,0,-1);
							$ret .= $args.")\r\n";
							break;
					}
				} else {
					if ($f->Name != "") $ret .= $f->Name;
					else $ret .= "func_".dechex($inst->operands[0])."()\r\n";
				}
			} else {
				if ($f->Flags & 2) {
					$ret .= "exported ";
					if ($f->FExportDecl->isVoid) $ret .= "void ";
					else {
						if (property_exists($f->FExportDecl->ReturnType,"ExportName")) $ret .= $f->FExportDecl->ReturnType->ExportName;
						else $ret .= $this->DumpType($f->FExportDecl->ReturnType);
						$ret .= " ";
					}
					$ret .= $f->FExportName."(";
					$pi = 1;
					$args = "";
					foreach ($f->FExportDecl->params as $fp) {
						if ($fp->modeIn) $args .= "in ";
						else $args .= "out ";
						if (property_exists($fp->type,"ExportName")) $args .= $fp->type->ExportName;
						else $args .= $this->DumpType($fp->type);
						$args .= " Arg".($pi++).",";
					}
					$args = substr($args,0,-1);
					$ret .= $args.")\r\n";
				} else {
					$ret .= "func_".dechex($inst->operands[0])."()\r\n";
				}
			}

			if (property_exists($f,"FData")) $ret .= $this->DisasmFunc($f->FData);
			$ret .= "\r\n";
		}
		return $ret;
	}
}
