<?php
declare(strict_types=1);

namespace SjI\FfiZts\Elf;

/**
 * Single Elf64_Dyn entry from the .dynamic section.
 *
 * d_un is overloaded into d_val (integer) or d_ptr (vaddr) depending
 * on $tag. The patcher does not distinguish at the type level; both
 * are stored in $value.
 */
final class DynamicEntry
{
    public function __construct(
        public int $tag,
        public int $value,
    ) {
    }

    public static function unpack(string $bytes, int $at): self
    {
        $u = unpack('Ptag/Pvalue', substr($bytes, $at, ElfConstants::DYN_ENTRY_SIZE));
        return new self($u['tag'], $u['value']);
    }

    public function pack(): string
    {
        return pack('PP', $this->tag, $this->value);
    }
}
