<?php

require_once('IFPSDisassembler.php');

if ($argc < 2) {
	echo "DisIFPS: PascalScript disassembler\r\n";
	echo "Usage: php ".$argv[0]." <file> [out]\r\n";
	echo "file: compiled PascalScript file to disassemble\r\n";
	echo "out: file to output to; if not passed, disassembly will be written to standard output\r\n";
	die();
}

$disasm = (new IFPSDisassembler(IFPS::LoadFile($argv[1])))->Disassemble();
if ($argc < 3) {
	echo $disasm."\r\n";
} else file_put_contents($argv[2],$disasm);