<?php

// IFPSOpcodes: detailing IFPS VM opcodes.

final class IFPSOpcodes {
	// sometimes i named the opcodes different from their "official" names
	// this is because of my personal preference only, i made this for myself after all :)
	const Assign = 0;
	const Calculate = 1;
	const Push = 2;
	const PushVar = 3;
	const Pop = 4;
	const Call = 5;
	const Jump = 6;
	const JumpTrue = 7;
	const JumpFalse = 8;
	const Ret = 9;
	const SetStackType = 10;
	const PushType = 11;
	const Compare = 12;
	const CallVar = 13;
	const SetPtr = 14;
	const LogicalNot = 15;
	const Neg = 16;
	const SetFlag = 17;
	const JumpFlag = 18;
	const PushEH = 19;
	const PopEH = 20;
	const Not = 21;
	const SetCopyPointer = 22;
	const Inc = 23;
	const Dec = 24;
	const PopJump = 25;
	const PopPopJump = 26;
	const Nop = 0xff;
	
	private function __construct() { }
}