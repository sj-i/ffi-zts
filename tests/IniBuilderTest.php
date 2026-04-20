<?php
declare(strict_types=1);

namespace SjI\FfiZts\Tests;

use PHPUnit\Framework\TestCase;
use SjI\FfiZts\Config;
use SjI\FfiZts\Extension\Extension;
use SjI\FfiZts\IniBuilder;

final class IniBuilderTest extends TestCase
{
    public function testIncludesExtensionDirAndExtensionLines(): void
    {
        $config = (new Config(libphpPath: '/x/libphp.so'))
            ->withExtensionDir('/x/ext')
            ->withExtension(new Extension('parallel', '/x/ext/parallel.so'))
            ->withExtension(new Extension('opcache', '/x/ext/opcache.so', isZendExtension: true))
            ->withIniEntry('display_errors', '0');

        $ini = IniBuilder::build($config);

        $this->assertStringContainsString('extension_dir=/x/ext', $ini);
        $this->assertStringContainsString('extension=/x/ext/parallel.so', $ini);
        $this->assertStringContainsString('zend_extension=/x/ext/opcache.so', $ini);
        $this->assertStringContainsString('display_errors=0', $ini);
    }
}
