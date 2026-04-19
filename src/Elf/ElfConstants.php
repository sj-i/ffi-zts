<?php
declare(strict_types=1);

namespace SjI\FfiZts\Elf;

/**
 * ELF64 little-endian constants used by the patcher.
 *
 * Scope is intentionally narrow (DT_NEEDED / DT_RUNPATH addition on
 * Linux x86_64 / aarch64 shared objects), so this is not a complete
 * ELF spec mirror.
 */
final class ElfConstants
{
    public const EI_CLASS_64 = 2;
    public const EI_DATA_LE  = 1;

    public const EM_X86_64   = 62;
    public const EM_AARCH64  = 183;

    public const ET_DYN      = 3;

    public const PT_NULL     = 0;
    public const PT_LOAD     = 1;
    public const PT_DYNAMIC  = 2;
    public const PT_PHDR     = 6;

    public const PF_X        = 1;
    public const PF_W        = 2;
    public const PF_R        = 4;

    public const DT_NULL     = 0;
    public const DT_NEEDED   = 1;
    public const DT_STRTAB   = 5;
    public const DT_STRSZ    = 10;
    public const DT_RPATH    = 15;
    public const DT_RUNPATH  = 29;

    public const ELF_HEADER_SIZE = 64;
    public const PHENT_SIZE      = 56;
    public const DYN_ENTRY_SIZE  = 16;
    public const PAGE_ALIGN      = 0x1000;

    public const ELF_MAGIC = "\x7fELF";
}
