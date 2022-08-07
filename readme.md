# IFPSTools

Superseded by [IFPSTools.NET](https://github.com/Wack0/IFPSTools.NET).

This repository contains tools for working with RemObjects PascalScript files, made because the official tools were too weak for my needs (reversing malicious Inno Setup `CompiledCode.bin` files).

Included is:

- IFPS.php - a library for loading and working with compiled PascalScript files. The loader is basically a port of the [original Pascal code](https://github.com/remobjects/pascalscript), with some bugs fixed.
- IFPSDisassembler.php - a library to disassemble a loaded PascalScript file, that generates a disassembly that in my opinion is superior to the official disassembler, when dealing with static analysis.
- disIFPS.php - a command line tool that wraps the functionality of the above two libraries.

Known bugs:
- Values of the "Extended" type do not get parsed properly; it is unlikely that this will ever be fixed.

Possible future tools, that I will think about developing if the demand exists:
- additions to IFPS.php to save out a compiled PascalScript file, and to create a new file, to allow for an IFPS assembler to be made, and for easy modification / patching of an existing file.
- an IFPS bytecode decompiler back into PascalScript.

Using disIFPS:
- Obtain a PascalScript file. When dealing with Inno Setup files, `innounp` can be used to extract the contents, including `CompiledCode.bin`. I have seen an "anti-unpacking" method where Inno Setup files are encrypted, and the included `CompiledCode.bin` (which is not encrypted) skips past the password entry screen by inserting the valid password (included with the PascalScript file as a string or obfuscated string). To work around this, use `innounp` to extract just the `CompiledCode.bin`.
- `php DisIFPS.php <PascalScript file> [disassembly.txt]` - if an output path is not given, the output will be written to standard output.

Feel free to submit pull requests; I will look at them when I get time.
