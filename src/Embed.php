<?php
declare(strict_types=1);

namespace SjI\FfiZts;

use FFI;
use FFI\CData;
use SjI\FfiZts\Exception\EmbedException;
use SjI\FfiZts\Extension\Extension;

/**
 * Embeds a ZTS libphp.so inside an NTS PHP host through FFI.
 *
 * See docs/DESIGN.md §4 for why the embed is loaded with
 * RTLD_NOW | RTLD_GLOBAL | RTLD_DEEPBIND. In short: DEEPBIND pins
 * libphp.so to its own ZTS copies of the Zend runtime even though
 * the host binary already exports NTS copies, while GLOBAL lets
 * subsequently-dlopen'd ZTS extensions (parallel.so etc.) see
 * those ZTS symbols.
 *
 * This class is a stateful lifecycle handle. Build the config with
 * the with*() helpers, call boot() (or let runScript() do it), and
 * finally call shutdown(). Embeds are not re-entrant: once
 * shutdown() has been called, a fresh instance is required.
 */
final class Embed
{
    private const RTLD_NOW      = 2;
    private const RTLD_GLOBAL   = 0x00100;
    private const RTLD_DEEPBIND = 0x00008;

    private FFI $libc;
    private CData $libphp; // void *
    private FFI $ffi;

    private CData $tsrmStartup;
    private CData $tsrmShutdown;
    private CData $zendSignalStartup;
    private CData $sapiStartup;
    private CData $sapiShutdown;
    private CData $phpRequestStartup;
    private CData $phpRequestShutdown;
    private CData $phpModuleStartup;
    private CData $phpModuleShutdown;
    private CData $moduleShutdownWrapper;
    private CData $zendStreamInit;
    private CData $phpExecuteScript;
    private CData $zendDestroyFh;
    private ?CData $zendSetDlUseDeepbind = null;

    private ?CData $module = null;
    /** @var list<CData> */
    private array $cstrings = [];
    /** @var list<\Closure> */
    private array $callbacks = [];

    private bool $booted = false;

    public function __construct(private Config $config)
    {
    }

    public function config(): Config
    {
        return $this->config;
    }

    // -- fluent configuration ----------------------------------------

    public function withExtension(Extension $ext): self
    {
        $this->assertNotBooted('withExtension');
        return new self($this->config->withExtension($ext));
    }

    public function withExtensionDir(string $dir): self
    {
        $this->assertNotBooted('withExtensionDir');
        return new self($this->config->withExtensionDir($dir));
    }

    public function withIniEntry(string $key, string $value): self
    {
        $this->assertNotBooted('withIniEntry');
        return new self($this->config->withIniEntry($key, $value));
    }

    public function withIniFile(string $path): self
    {
        $this->assertNotBooted('withIniFile');
        return new self($this->config->withIniFile($path));
    }

    // -- lifecycle ---------------------------------------------------

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        Platform::assertSupported();
        $this->loadLibc();
        $this->loadLibphp();
        $this->bindFfi();
        $this->bindSymbols();
        $this->buildModule();

        ($this->tsrmStartup)();
        ($this->zendSignalStartup)();
        ($this->sapiStartup)(FFI::addr($this->module));
        ($this->module->startup)(FFI::addr($this->module));
        $this->booted = true;
    }

    public function executeScript(string $file): void
    {
        if (!$this->booted) {
            throw new EmbedException('Embed::executeScript called before boot()');
        }
        ($this->phpRequestStartup)();
        $fh = $this->ffi->new('struct zend_file_handle');
        ($this->zendStreamInit)(FFI::addr($fh), $file);
        ($this->phpExecuteScript)(FFI::addr($fh));
        ($this->zendDestroyFh)(FFI::addr($fh));
        ($this->phpRequestShutdown)(null);
    }

    public function runScript(string $file): void
    {
        $this->boot();
        try {
            $this->executeScript($file);
        } finally {
            $this->shutdown();
        }
    }

    public function shutdown(): void
    {
        if (!$this->booted) {
            return;
        }
        foreach ($this->cstrings as $c) {
            try { FFI::free($c); } catch (\Throwable) {}
        }
        $this->cstrings = [];
        $this->callbacks = [];
        ($this->phpModuleShutdown)();
        ($this->sapiShutdown)();
        ($this->tsrmShutdown)();
        $this->libc->dlclose($this->libphp);
        $this->booted = false;
    }

    // -- internals ---------------------------------------------------

    private function assertNotBooted(string $op): void
    {
        if ($this->booted) {
            throw new EmbedException("{$op}() is only valid before boot(); create a fresh Embed instead");
        }
    }

    private function loadLibc(): void
    {
        // libdl is folded into libc on glibc 2.34+.
        $this->libc = FFI::cdef(<<<EOD
            void *dlopen(const char *file, int mode);
            void *dlsym(void *handle, const char *name);
            char *dlerror(void);
            int   dlclose(void *handle);
        EOD, 'libc.so.6');
    }

    private function loadLibphp(): void
    {
        $path = $this->config->libphpPath;
        if (!is_readable($path)) {
            throw new EmbedException("libphp.so not readable: {$path}");
        }
        $flags = self::RTLD_NOW | self::RTLD_GLOBAL | self::RTLD_DEEPBIND;
        $h = $this->libc->dlopen($path, $flags);
        if (FFI::isNull($h)) {
            $err = $this->libc->dlerror();
            throw new EmbedException('dlopen(libphp.so) failed: ' . FFI::string($err));
        }
        $this->libphp = $h;
    }

    private function bindFfi(): void
    {
        $this->ffi = FFI::cdef(IniBuilder::sapiCdefFragment());
    }

    private function sym(string $name, string $fnPtrType): CData
    {
        $addr = $this->libc->dlsym($this->libphp, $name);
        if (FFI::isNull($addr)) {
            $err = $this->libc->dlerror();
            throw new EmbedException("dlsym({$name}) failed: " . FFI::string($err));
        }
        $ptr = $this->ffi->new($fnPtrType);
        FFI::memcpy(FFI::addr($ptr), FFI::addr($addr), FFI::sizeof($ptr));
        return $ptr;
    }

    private function symOptional(string $name, string $fnPtrType): ?CData
    {
        $addr = $this->libc->dlsym($this->libphp, $name);
        if (FFI::isNull($addr)) {
            return null;
        }
        $ptr = $this->ffi->new($fnPtrType);
        FFI::memcpy(FFI::addr($ptr), FFI::addr($addr), FFI::sizeof($ptr));
        return $ptr;
    }

    private function bindSymbols(): void
    {
        $this->tsrmStartup           = $this->sym('php_tsrm_startup',            'void (*)(void)');
        $this->tsrmShutdown          = $this->sym('tsrm_shutdown',               'void (*)(void)');
        $this->zendSignalStartup     = $this->sym('zend_signal_startup',         'void (*)(void)');
        $this->sapiStartup           = $this->sym('sapi_startup',                'void (*)(void *)');
        $this->sapiShutdown          = $this->sym('sapi_shutdown',               'void (*)(void)');
        $this->phpRequestStartup     = $this->sym('php_request_startup',         'int (*)(void)');
        $this->phpRequestShutdown    = $this->sym('php_request_shutdown',        'void (*)(void *)');
        $this->phpModuleStartup      = $this->sym('php_module_startup',          'int (*)(void *, void *)');
        $this->phpModuleShutdown     = $this->sym('php_module_shutdown',         'int (*)(void)');
        $this->moduleShutdownWrapper = $this->sym('php_module_shutdown_wrapper', 'int (*)(void *)');
        $this->zendStreamInit        = $this->sym('zend_stream_init_filename',  'void (*)(struct zend_file_handle *, const char *)');
        $this->phpExecuteScript      = $this->sym('php_execute_script',         'uint8_t (*)(struct zend_file_handle *)');
        $this->zendDestroyFh         = $this->sym('zend_destroy_file_handle',   'void (*)(struct zend_file_handle *)');

        // PHP 8.5 introduced `zend_dl_use_deepbind` (php/php-src#18612)
        // which gates RTLD_DEEPBIND on extension dlopen behind this
        // runtime flag. Default is `false`, so without opting in
        // parallel.so's references to zend_register_internal_class_*
        // etc. resolve through the host NTS binary (which re-exports
        // the whole Zend API on 8.5 thanks to opcache going static)
        // and the embed segfaults on first MINIT. On 8.4 the setter
        // doesn't exist; stay silent there - 8.4 keeps the old
        // unconditional-DEEPBIND behaviour without any opt-in.
        $this->zendSetDlUseDeepbind  = $this->symOptional('zend_set_dl_use_deepbind', 'void (*)(uint8_t)');
    }

    private function buildModule(): void
    {
        $mod = $this->ffi->new('struct sapi_module_struct');
        FFI::memset(FFI::addr($mod), 0, FFI::sizeof($mod));
        $mod->name        = $this->cString('ffi-zts');
        $mod->pretty_name = $this->cString('ffi-zts meta-SAPI');

        // startup is deferred until sapi_startup() has installed the
        // module, so we can push ini_entries onto the already-installed
        // sapi_module_struct before php_module_startup reads them.
        $startup = function ($module) {
            $ini = IniBuilder::build($this->config);
            $this->module->ini_entries = $this->cString($ini);
            // PHP 8.5+: re-enable RTLD_DEEPBIND for extension dlopen.
            // Must be flipped on BEFORE php_module_startup triggers
            // zend_startup_modules / php_load_shlib.
            if ($this->zendSetDlUseDeepbind !== null) {
                ($this->zendSetDlUseDeepbind)(1);
            }
            return ($this->phpModuleStartup)($module, null);
        };
        $ubWrite  = function (string $s, int $n) { echo $s; return $n; };
        $sapiErr  = function ($type, $fmt) { fwrite(STDERR, "[ffi-zts sapi_error {$type}] {$fmt}\n"); };
        $sendHdr  = function ($h, $c) {};
        $readCk   = function () { return null; };
        $regSvr   = function ($a) {};
        $logMsg   = function ($msg, $type) { fwrite(STDERR, "[ffi-zts log {$type}] {$msg}\n"); };

        $mod->startup                   = $startup;
        $mod->shutdown                  = $this->moduleShutdownWrapper;
        $mod->ub_write                  = $ubWrite;
        $mod->sapi_error                = $sapiErr;
        $mod->send_header               = $sendHdr;
        $mod->read_cookies              = $readCk;
        $mod->register_server_variables = $regSvr;
        $mod->log_message               = $logMsg;

        $this->callbacks = [$startup, $ubWrite, $sapiErr, $sendHdr, $readCk, $regSvr, $logMsg];
        $this->module = $mod;
    }

    private function cString(string $s): CData
    {
        $len = strlen($s);
        $c = $this->ffi->new(sprintf('char[%d]', $len + 1), false);
        FFI::memset(FFI::addr($c), 0, $len + 1);
        FFI::memcpy($c, $s, $len);
        $this->cstrings[] = $c;
        return $c;
    }
}
