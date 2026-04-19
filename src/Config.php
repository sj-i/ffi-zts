<?php
declare(strict_types=1);

namespace SjI\FfiZts;

use SjI\FfiZts\Extension\Extension;

/**
 * Immutable configuration bundle passed to Embed.
 *
 * The with*() methods return new instances so the caller can build up
 * the configuration fluently (cf. FfiZts::boot()->withExtension(...)).
 */
final class Config
{
    /**
     * @param list<Extension>        $extensions
     * @param array<string, string>  $iniEntries
     */
    public function __construct(
        public readonly string $libphpPath,
        public readonly array $extensions = [],
        public readonly ?string $iniPath = null,
        public readonly array $iniEntries = [],
        public readonly ?string $extensionDir = null,
    ) {
    }

    public function withExtension(Extension $ext): self
    {
        return new self(
            $this->libphpPath,
            [...$this->extensions, $ext],
            $this->iniPath,
            $this->iniEntries,
            $this->extensionDir,
        );
    }

    public function withIniEntry(string $key, string $value): self
    {
        return new self(
            $this->libphpPath,
            $this->extensions,
            $this->iniPath,
            [...$this->iniEntries, $key => $value],
            $this->extensionDir,
        );
    }

    public function withIniFile(string $path): self
    {
        return new self(
            $this->libphpPath,
            $this->extensions,
            $path,
            $this->iniEntries,
            $this->extensionDir,
        );
    }

    public function withExtensionDir(string $dir): self
    {
        return new self(
            $this->libphpPath,
            $this->extensions,
            $this->iniPath,
            $this->iniEntries,
            $dir,
        );
    }
}
