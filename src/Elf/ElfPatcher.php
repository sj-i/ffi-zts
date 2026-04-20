<?php
declare(strict_types=1);

namespace SjI\FfiZts\Elf;

use SjI\FfiZts\Exception\ElfException;

/**
 * Pure-PHP ELF64 LE shared-object patcher.
 *
 * Operations supported (per docs/DESIGN.md §5.4):
 *   - add a DT_NEEDED entry
 *   - set DT_RUNPATH
 *
 * Strategy is the same one patchelf uses:
 *   1. Build extended .dynstr (old + new strings)
 *   2. Build new .dynamic (old, with DT_STRTAB/DT_STRSZ updated and
 *      DT_NEEDED / DT_RUNPATH inserted before the trailing DT_NULL)
 *   3. Append a new PT_LOAD segment at end of file holding
 *      [new .dynstr][new .dynamic][new program header table]
 *   4. Move e_phoff to the new PHT, bump e_phnum by one
 *   5. Update PT_PHDR (if present) and PT_DYNAMIC entries in the new
 *      PHT to point at their new file/vaddr locations
 *
 * Inputs are not validated against malicious ELFs beyond "this is
 * actually an ELF64 LE shared object for x86_64 or aarch64" -- the
 * intended consumer is a known-shape pecl/parallel build.
 */
final class ElfPatcher
{
    /**
     * @param list<string> $addNeeded  library basenames (e.g. ["libphp.so"])
     */
    public static function patchFile(
        string $inputPath,
        array $addNeeded,
        ?string $runpath = null,
        ?string $outputPath = null,
    ): void {
        $bytes = @file_get_contents($inputPath);
        if ($bytes === false) {
            throw new ElfException("unable to read input ELF: {$inputPath}");
        }
        $patched = self::patchBytes($bytes, $addNeeded, $runpath);
        $out = $outputPath ?? $inputPath;
        if (@file_put_contents($out, $patched) === false) {
            throw new ElfException("unable to write patched ELF: {$out}");
        }
        @chmod($out, 0755);
    }

    /**
     * @param list<string> $addNeeded
     */
    public static function patchBytes(string $bytes, array $addNeeded, ?string $runpath = null): string
    {
        $elf = ElfFile::fromBytes($bytes);

        // 1. Extended .dynstr.
        $newDynstr = $elf->dynstr;
        $needOffsets = [];
        foreach ($addNeeded as $name) {
            $needOffsets[] = strlen($newDynstr);
            $newDynstr .= $name . "\0";
        }
        $runpathOffset = null;
        if ($runpath !== null) {
            $runpathOffset = strlen($newDynstr);
            $newDynstr .= $runpath . "\0";
        }

        // 2. Rebuild .dynamic. Strip trailing DT_NULL slots; replace
        //    DT_RUNPATH/DT_RPATH if a runpath was passed; append our
        //    new DT_NEEDED / DT_RUNPATH; one DT_NULL terminator.
        $entries = [];
        foreach ($elf->dynamic as $e) {
            if ($e->tag === ElfConstants::DT_NULL) {
                continue;
            }
            if ($runpath !== null && in_array($e->tag, [ElfConstants::DT_RUNPATH, ElfConstants::DT_RPATH], true)) {
                continue;
            }
            $entries[] = new DynamicEntry($e->tag, $e->value);
        }
        foreach ($addNeeded as $i => $_name) {
            $entries[] = new DynamicEntry(ElfConstants::DT_NEEDED, $needOffsets[$i]);
        }
        if ($runpath !== null) {
            $entries[] = new DynamicEntry(ElfConstants::DT_RUNPATH, $runpathOffset);
        }
        $entries[] = new DynamicEntry(ElfConstants::DT_NULL, 0);

        // 3. Decide layout for the appended block.
        $align = ElfConstants::PAGE_ALIGN;
        $blockFileOffset = self::alignUp(strlen($bytes), $align);
        $blockVaddr      = self::alignUp($elf->highestVaddrEnd(), $align);

        $dynstrOff   = 0;
        $dynstrSize  = strlen($newDynstr);
        $dynamicOff  = self::alignUp($dynstrOff + $dynstrSize, 8);
        $dynamicSize = count($entries) * ElfConstants::DYN_ENTRY_SIZE;
        $phtOff      = self::alignUp($dynamicOff + $dynamicSize, 8);
        $newPhnum    = $elf->ePhnum + 1;
        $phtSize     = $newPhnum * ElfConstants::PHENT_SIZE;
        $blockSize   = $phtOff + $phtSize;

        // 4. Patch DT_STRTAB / DT_STRSZ in the new entries to point at
        //    the new .dynstr.
        $newStrtabVaddr = $blockVaddr + $dynstrOff;
        foreach ($entries as $e) {
            if ($e->tag === ElfConstants::DT_STRTAB) {
                $e->value = $newStrtabVaddr;
            } elseif ($e->tag === ElfConstants::DT_STRSZ) {
                $e->value = $dynstrSize;
            }
        }

        // 5. Build the new program header table: copy existing entries,
        //    redirect PT_PHDR and PT_DYNAMIC, append a new PT_LOAD for
        //    the appended block.
        $newPht = [];
        foreach ($elf->programHeaders as $ph) {
            $copy = new ProgramHeader(
                $ph->type, $ph->flags, $ph->offset, $ph->vaddr, $ph->paddr,
                $ph->filesz, $ph->memsz, $ph->align,
            );
            if ($copy->type === ElfConstants::PT_PHDR) {
                $copy->offset = $blockFileOffset + $phtOff;
                $copy->vaddr  = $blockVaddr + $phtOff;
                $copy->paddr  = $copy->vaddr;
                $copy->filesz = $phtSize;
                $copy->memsz  = $phtSize;
            } elseif ($copy->type === ElfConstants::PT_DYNAMIC) {
                $copy->offset = $blockFileOffset + $dynamicOff;
                $copy->vaddr  = $blockVaddr + $dynamicOff;
                $copy->paddr  = $copy->vaddr;
                $copy->filesz = $dynamicSize;
                $copy->memsz  = $dynamicSize;
            }
            $newPht[] = $copy;
        }
        $newPht[] = new ProgramHeader(
            type:   ElfConstants::PT_LOAD,
            flags:  ElfConstants::PF_R | ElfConstants::PF_W,
            offset: $blockFileOffset,
            vaddr:  $blockVaddr,
            paddr:  $blockVaddr,
            filesz: $blockSize,
            memsz:  $blockSize,
            align:  $align,
        );

        // 6. Patch the ELF header in place: e_phoff -> new PHT, e_phnum
        //    bumped by one. We mutate the original byte string, which
        //    is fine because PHP strings are copy-on-write.
        $out = $bytes;
        // e_phoff is at byte 32 (8 bytes), e_phnum at byte 56 (2 bytes)
        $newEphoff = $blockFileOffset + $phtOff;
        $out = substr_replace($out, pack('P', $newEphoff), 32, 8);
        $out = substr_replace($out, pack('v', $newPhnum), 56, 2);

        // 7. Pad to the new block offset, then append block content:
        //    [dynstr][pad][dynamic][pad][pht]
        $pad = str_repeat("\0", $blockFileOffset - strlen($out));
        $block  = $newDynstr;
        $block .= str_repeat("\0", $dynamicOff - $dynstrSize);
        foreach ($entries as $e) {
            $block .= $e->pack();
        }
        $block .= str_repeat("\0", $phtOff - ($dynamicOff + $dynamicSize));
        foreach ($newPht as $ph) {
            $block .= $ph->pack();
        }
        return $out . $pad . $block;
    }

    private static function alignUp(int $value, int $align): int
    {
        if ($align <= 1) {
            return $value;
        }
        $rem = $value % $align;
        if ($rem === 0) {
            return $value;
        }
        return $value + ($align - $rem);
    }
}
