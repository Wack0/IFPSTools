<?php

// IFPSTypes: listing IFPS type codes as used in compiled IFPS files.

final class IFPSTypes {
	// copied from ifps uPSUtils.pas
	const ReturnAddress = 0;
	const U8 = 1;
	const S8 = 2;
	const U16 = 3;
	const S16 = 4;
	const U32 = 5;
	const S32 = 6;
	const Single = 7;
	const Double = 8;
	const Extended = 9;
	const String = 10;
	const Record = 11;
	const _Array = 12;
	const Pointer = 13;
	const PChar = 14;
	const ResourcePointer = 15;
	const Variant = 16;
	const S64 = 17;
	const Char = 18;
	const WideString = 19;
	const WideChar = 20;
	const ProcPtr = 21;
	const StaticArray = 22;
	const Set = 23;
	const Currency = 24;
	const _Class = 25;
	const _Interface = 26;
	const NotificationVariant = 27;
	const UnicodeString = 28;
	const Type = 130;
	const Enum = 129;
	const ExtClass = 131;

	private function __construct() { }
}
