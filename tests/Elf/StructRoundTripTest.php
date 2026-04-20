<?php
declare(strict_types=1);

namespace SjI\FfiZts\Tests\Elf;

use PHPUnit\Framework\TestCase;
use SjI\FfiZts\Elf\DynamicEntry;
use SjI\FfiZts\Elf\ElfConstants;
use SjI\FfiZts\Elf\ProgramHeader;

final class StructRoundTripTest extends TestCase
{
    public function testProgramHeaderRoundTrip(): void
    {
        $ph = new ProgramHeader(
            type:   ElfConstants::PT_LOAD,
            flags:  ElfConstants::PF_R | ElfConstants::PF_X,
            offset: 0x1000,
            vaddr:  0x401000,
            paddr:  0x401000,
            filesz: 0x2345,
            memsz:  0x2345,
            align:  ElfConstants::PAGE_ALIGN,
        );
        $bytes = $ph->pack();
        $this->assertSame(ElfConstants::PHENT_SIZE, strlen($bytes));

        $back = ProgramHeader::unpack($bytes, 0);
        $this->assertSame($ph->type,   $back->type);
        $this->assertSame($ph->flags,  $back->flags);
        $this->assertSame($ph->offset, $back->offset);
        $this->assertSame($ph->vaddr,  $back->vaddr);
        $this->assertSame($ph->paddr,  $back->paddr);
        $this->assertSame($ph->filesz, $back->filesz);
        $this->assertSame($ph->memsz,  $back->memsz);
        $this->assertSame($ph->align,  $back->align);
    }

    public function testDynamicEntryRoundTrip(): void
    {
        $e = new DynamicEntry(ElfConstants::DT_NEEDED, 0xDEAD_BEEF);
        $bytes = $e->pack();
        $this->assertSame(ElfConstants::DYN_ENTRY_SIZE, strlen($bytes));

        $back = DynamicEntry::unpack($bytes, 0);
        $this->assertSame(ElfConstants::DT_NEEDED, $back->tag);
        $this->assertSame(0xDEAD_BEEF, $back->value);
    }
}
