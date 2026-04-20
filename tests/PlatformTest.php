<?php
declare(strict_types=1);

namespace SjI\FfiZts\Tests;

use PHPUnit\Framework\TestCase;
use SjI\FfiZts\Platform;

final class PlatformTest extends TestCase
{
    public function testMapsWithMuslX86LoaderReportsMusl(): void
    {
        $maps = "7f0000000000-7f0000010000 r-xp 00000000 fd:01 1 /lib/ld-musl-x86_64.so.1\n";
        $this->assertSame('musl', Platform::detectLibcFromMaps($maps));
    }

    public function testMapsWithMuslAarch64LoaderReportsMusl(): void
    {
        $maps = "7f0000000000-7f0000010000 r-xp 00000000 fd:01 1 /lib/ld-musl-aarch64.so.1\n";
        $this->assertSame('musl', Platform::detectLibcFromMaps($maps));
    }

    public function testMapsWithGlibcLibcReportsGlibc(): void
    {
        $maps = "7f0000000000-7f0000010000 r--p 00000000 fd:01 1 /usr/lib/x86_64-linux-gnu/libc.so.6\n";
        $this->assertSame('glibc', Platform::detectLibcFromMaps($maps));
    }

    public function testMapsWithGlibcVersionedLibcReportsGlibc(): void
    {
        $maps = "7f0000000000-7f0000010000 r--p 00000000 fd:01 1 /usr/lib/libc-2.31.so\n";
        $this->assertSame('glibc', Platform::detectLibcFromMaps($maps));
    }

    public function testMapsWithGlibcLoaderReportsGlibc(): void
    {
        $maps = "7f0000000000-7f0000010000 r--p 00000000 fd:01 1 /lib64/ld-linux-x86-64.so.2\n";
        $this->assertSame('glibc', Platform::detectLibcFromMaps($maps));
    }

    public function testMapsWithoutLibcEvidenceReturnsNull(): void
    {
        $maps = "00000000-00000000 r--p 00000000 00:00 0 \n";
        $this->assertNull(Platform::detectLibcFromMaps($maps));
    }

    public function testGlibcLddVersionOutputReportsGlibc(): void
    {
        $out = "ldd (Ubuntu GLIBC 2.39-0ubuntu8.4) 2.39\nCopyright (C) 2024 Free Software Foundation, Inc.\n";
        $this->assertSame('glibc', Platform::detectLibcFromLddOutput($out));
    }

    public function testMuslLddOutputReportsMusl(): void
    {
        $out = "musl libc (x86_64)\nVersion 1.2.5\nDynamic Program Loader\n";
        $this->assertSame('musl', Platform::detectLibcFromLddOutput($out));
    }

    public function testUnknownLddOutputReturnsNull(): void
    {
        $this->assertNull(Platform::detectLibcFromLddOutput("some unrelated output\n"));
    }

    public function testLibcReturnsKnownVariantOnHost(): void
    {
        $this->assertContains(Platform::libc(), ['glibc', 'musl']);
    }
}
