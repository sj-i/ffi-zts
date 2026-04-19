<?php
declare(strict_types=1);

namespace SjI\FfiZts\Elf;

/**
 * Parsed ELF64 program header entry (a.k.a. Elf64_Phdr).
 */
final class ProgramHeader
{
    public function __construct(
        public int $type,
        public int $flags,
        public int $offset,
        public int $vaddr,
        public int $paddr,
        public int $filesz,
        public int $memsz,
        public int $align,
    ) {
    }

    public static function unpack(string $bytes, int $at): self
    {
        $b = substr($bytes, $at, ElfConstants::PHENT_SIZE);
        $u = unpack('Vtype/Vflags/Poffset/Pvaddr/Ppaddr/Pfilesz/Pmemsz/Palign', $b);
        return new self(
            $u['type'], $u['flags'],
            $u['offset'], $u['vaddr'], $u['paddr'],
            $u['filesz'], $u['memsz'], $u['align'],
        );
    }

    public function pack(): string
    {
        return pack(
            'VVPPPPPP',
            $this->type, $this->flags,
            $this->offset, $this->vaddr, $this->paddr,
            $this->filesz, $this->memsz, $this->align,
        );
    }
}
