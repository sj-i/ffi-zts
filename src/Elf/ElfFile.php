<?php
declare(strict_types=1);

namespace SjI\FfiZts\Elf;

use SjI\FfiZts\Exception\ElfException;

/**
 * Parses an ELF64 little-endian shared object into the subset of
 * structures the patcher needs: ELF header, program headers, the
 * .dynamic array, and the .dynstr bytes.
 *
 * Only operates on what the design doc calls out -- ET_DYN, x86_64
 * or aarch64, ELFCLASS64, ELFDATA2LSB. Anything else throws.
 */
final class ElfFile
{
    /** @var list<ProgramHeader> */
    public array $programHeaders = [];
    /** @var list<DynamicEntry> */
    public array $dynamic = [];

    public int $eEntry = 0;
    public int $ePhoff = 0;
    public int $eShoff = 0;
    public int $ePhnum = 0;
    public int $ePhentsize = 0;
    public int $eMachine = 0;
    public int $eType = 0;

    public ?ProgramHeader $phdrPhdr   = null;
    public ?ProgramHeader $dynamicPhdr = null;

    public int $strtabVaddr = 0;
    public int $strtabSize  = 0;
    public int $strtabFileOffset = 0;

    public string $dynstr = '';

    private function __construct(public string $bytes)
    {
    }

    public static function fromBytes(string $bytes): self
    {
        $self = new self($bytes);
        $self->parseHeader();
        $self->parseProgramHeaders();
        $self->parseDynamic();
        return $self;
    }

    public static function fromFile(string $path): self
    {
        $b = @file_get_contents($path);
        if ($b === false) {
            throw new ElfException("unable to read ELF file: {$path}");
        }
        return self::fromBytes($b);
    }

    private function parseHeader(): void
    {
        if (strlen($this->bytes) < ElfConstants::ELF_HEADER_SIZE) {
            throw new ElfException('file too small to be an ELF64');
        }
        if (substr($this->bytes, 0, 4) !== ElfConstants::ELF_MAGIC) {
            throw new ElfException('not an ELF file (bad magic)');
        }
        $eiClass = ord($this->bytes[4]);
        $eiData  = ord($this->bytes[5]);
        if ($eiClass !== ElfConstants::EI_CLASS_64) {
            throw new ElfException('only ELFCLASS64 is supported');
        }
        if ($eiData !== ElfConstants::EI_DATA_LE) {
            throw new ElfException('only ELFDATA2LSB (little-endian) is supported');
        }
        $u = unpack(
            'vtype/vmachine/Vversion/Pentry/Pphoff/Pshoff/Vflags/vehsize/vphentsize/vphnum/vshentsize/vshnum/vshstrndx',
            substr($this->bytes, 16, ElfConstants::ELF_HEADER_SIZE - 16),
        );
        $this->eType      = $u['type'];
        $this->eMachine   = $u['machine'];
        $this->eEntry     = $u['entry'];
        $this->ePhoff     = $u['phoff'];
        $this->eShoff     = $u['shoff'];
        $this->ePhentsize = $u['phentsize'];
        $this->ePhnum     = $u['phnum'];

        if ($this->eType !== ElfConstants::ET_DYN) {
            throw new ElfException('only ET_DYN (shared object) is supported');
        }
        if (!in_array($this->eMachine, [ElfConstants::EM_X86_64, ElfConstants::EM_AARCH64], true)) {
            throw new ElfException('unsupported e_machine: ' . $this->eMachine);
        }
        if ($this->ePhentsize !== ElfConstants::PHENT_SIZE) {
            throw new ElfException('unexpected e_phentsize: ' . $this->ePhentsize);
        }
    }

    private function parseProgramHeaders(): void
    {
        for ($i = 0; $i < $this->ePhnum; $i++) {
            $ph = ProgramHeader::unpack($this->bytes, $this->ePhoff + $i * ElfConstants::PHENT_SIZE);
            $this->programHeaders[] = $ph;
            if ($ph->type === ElfConstants::PT_PHDR) {
                $this->phdrPhdr = $ph;
            } elseif ($ph->type === ElfConstants::PT_DYNAMIC) {
                $this->dynamicPhdr = $ph;
            }
        }
        if ($this->dynamicPhdr === null) {
            throw new ElfException('no PT_DYNAMIC segment present; not a dynamically-linked shared object');
        }
    }

    private function parseDynamic(): void
    {
        $base = $this->dynamicPhdr->offset;
        $size = $this->dynamicPhdr->filesz;
        $count = intdiv($size, ElfConstants::DYN_ENTRY_SIZE);
        for ($i = 0; $i < $count; $i++) {
            $entry = DynamicEntry::unpack($this->bytes, $base + $i * ElfConstants::DYN_ENTRY_SIZE);
            $this->dynamic[] = $entry;
            if ($entry->tag === ElfConstants::DT_NULL) {
                // keep walking so we know the total count, but record string table from earlier entries
            } elseif ($entry->tag === ElfConstants::DT_STRTAB) {
                $this->strtabVaddr = $entry->value;
            } elseif ($entry->tag === ElfConstants::DT_STRSZ) {
                $this->strtabSize = $entry->value;
            }
        }
        if ($this->strtabVaddr === 0 || $this->strtabSize === 0) {
            throw new ElfException('DT_STRTAB / DT_STRSZ missing in .dynamic');
        }
        $this->strtabFileOffset = $this->vaddrToOffset($this->strtabVaddr);
        $this->dynstr = substr($this->bytes, $this->strtabFileOffset, $this->strtabSize);
    }

    public function vaddrToOffset(int $vaddr): int
    {
        foreach ($this->programHeaders as $ph) {
            if ($ph->type !== ElfConstants::PT_LOAD) {
                continue;
            }
            $start = $ph->vaddr;
            $end   = $ph->vaddr + $ph->filesz;
            if ($vaddr >= $start && $vaddr < $end) {
                return $ph->offset + ($vaddr - $start);
            }
        }
        throw new ElfException(sprintf('vaddr 0x%x not contained in any PT_LOAD segment', $vaddr));
    }

    public function highestVaddrEnd(): int
    {
        $max = 0;
        foreach ($this->programHeaders as $ph) {
            if ($ph->type !== ElfConstants::PT_LOAD) {
                continue;
            }
            $end = $ph->vaddr + $ph->memsz;
            if ($end > $max) {
                $max = $end;
            }
        }
        return $max;
    }
}
