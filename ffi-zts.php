#!/usr/bin/env php
<?php
/**
 * ffi-zts.php
 *
 * Loads a ZTS libphp.so (embed SAPI) from a plain NTS PHP via FFI, then
 * executes a user PHP script inside that embedded ZTS interpreter.
 *
 * The embedded ZTS library is brought up in its own dynamic linker
 * namespace via dlmopen(LM_ID_NEWLM, ...), which isolates its symbols
 * from the host NTS binary and avoids clashes on things like
 * zend_register_functions / compiler_globals.
 *
 * Derived from https://github.com/adsr/php-meta-sapi (php-meta-sapi.php),
 * reduced to a one-shot script runner, adapted for NTS->ZTS use, and
 * extended to feed the embedded interpreter an ini so it loads
 * extensions such as parallel on its own.
 *
 * Usage:
 *   php ffi-zts.php --libphp-path=/path/to/zts/libphp.so \
 *       --ini=/path/to/zts/php.ini [--] script.php
 */
declare(strict_types=1);

const LM_ID_NEWLM    = -1;
const RTLD_NOW       = 2;
const RTLD_LOCAL     = 0;
const RTLD_GLOBAL    = 0x00100;
const RTLD_DEEPBIND  = 0x00008;

(new class {
    private ?string $libphp_path = null;
    private ?string $ini_path    = null;
    private ?string $script      = null;

    private FFI $libc;
    private FFI\CData $libphp;     // void* handle
    private FFI $ffi;              // typed cdef bound to libphp

    private ?FFI\CData $module = null;
    private array $free_list   = [];

    public function run(): void {
        $this->parseArgs();
        $this->loadLibc();
        $this->loadLibPhp();
        $this->bindFfi();
        $this->initPhp();
        $this->execScript($this->script);
        $this->deinitPhp();
    }

    private function parseArgs(): void {
        $opt = getopt('', ['libphp-path:', 'ini:'], $rest);
        $this->libphp_path = $opt['libphp-path'] ?? null;
        $this->ini_path    = $opt['ini']          ?? null;
        global $argv;
        $this->script = $argv[$rest] ?? null;
        if ($this->libphp_path === null || $this->script === null) {
            fwrite(STDERR, "usage: php ffi-zts.php --libphp-path=<so> [--ini=<file>] <script.php>\n");
            exit(1);
        }
    }

    private function loadLibc(): void {
        // libdl is folded into libc on glibc 2.34+.
        $this->libc = FFI::cdef(<<<EOD
            void *dlmopen(long nsid, const char *file, int mode);
            void *dlopen(const char *file, int mode);
            void *dlsym(void *handle, const char *name);
            char *dlerror(void);
            int   dlclose(void *handle);
        EOD, 'libc.so.6');
    }

    private function loadLibPhp(): void {
        // dlmopen would give the cleanest isolation, but glibc is known to
        // choke when a dlmopen'd library later dlopen()s another library
        // that pulls in libpthread symbols (e.g. parallel.so) -- the crash
        // happens deep in ld-linux's add_to_global_resize().
        //
        // As a workaround we use plain dlopen with RTLD_DEEPBIND, which
        // forces the loaded library to prefer its OWN definitions of
        // symbols over the ones already exported by the host NTS binary,
        // while still allowing normal in-process dlopen() to work.
        // RTLD_GLOBAL so that subsequently-dlopen'd extensions (parallel.so
        // etc.) can resolve ZTS core symbols like core_globals_offset out of
        // libphp.so; RTLD_DEEPBIND so that libphp.so itself keeps using its
        // own copies instead of the host NTS binary's. The host is already
        // BIND_NOW so adding globals afterwards doesn't affect it.
        $h = $this->libc->dlopen($this->libphp_path, RTLD_NOW | RTLD_GLOBAL | RTLD_DEEPBIND);
        if (FFI::isNull($h)) {
            $err = $this->libc->dlerror();
            throw new RuntimeException('dlopen failed: ' . FFI::string($err));
        }
        $this->libphp = $h;
    }

    private function bindFfi(): void {
        // Provide the cdef without a library; each callable will be
        // bound by dlsym'ing against the dlmopen'd handle and casting
        // to the right function-pointer type.
        $this->ffi = FFI::cdef($this->getCDefs());
    }

    /**
     * dlsym the given name from the isolated libphp and return an FFI
     * CData of the requested function-pointer type.
     */
    private function sym(string $name, string $fnPtrType): FFI\CData {
        $addr = $this->libc->dlsym($this->libphp, $name);
        if (FFI::isNull($addr)) {
            $err = $this->libc->dlerror();
            throw new RuntimeException("dlsym($name) failed: " . FFI::string($err));
        }
        $ptr = $this->ffi->new($fnPtrType);
        FFI::memcpy(FFI::addr($ptr), FFI::addr($addr), FFI::sizeof($ptr));
        return $ptr;
    }

    private function initPhp(): void {
        $this->tsrm_startup         = $this->sym('php_tsrm_startup',          'void (*)(void)');
        $this->tsrm_shutdown        = $this->sym('tsrm_shutdown',             'void (*)(void)');
        $this->zend_signal_startup  = $this->sym('zend_signal_startup',       'void (*)(void)');
        $this->sapi_startup         = $this->sym('sapi_startup',              'void (*)(void *)');
        $this->sapi_shutdown        = $this->sym('sapi_shutdown',             'void (*)(void)');
        $this->php_request_startup  = $this->sym('php_request_startup',       'int (*)(void)');
        $this->php_request_shutdown = $this->sym('php_request_shutdown',      'void (*)(void *)');
        $this->php_module_startup   = $this->sym('php_module_startup',        'int (*)(void *, void *)');
        $this->php_module_shutdown  = $this->sym('php_module_shutdown',       'int (*)(void)');
        $this->module_shutdown_wrap = $this->sym('php_module_shutdown_wrapper','int (*)(void *)');
        $this->zend_stream_init     = $this->sym('zend_stream_init_filename', 'void (*)(struct zend_file_handle *, const char *)');
        $this->php_execute_script   = $this->sym('php_execute_script',        'uint8_t (*)(struct zend_file_handle *)');
        $this->zend_destroy_fh      = $this->sym('zend_destroy_file_handle',  'void (*)(struct zend_file_handle *)');

        $this->initPhpModule();

        ($this->tsrm_startup)();
        ($this->zend_signal_startup)();
        ($this->sapi_startup)(FFI::addr($this->module));
        call_user_func($this->module->startup, FFI::addr($this->module));
    }

    private function execScript(string $file): void {
        ($this->php_request_startup)();

        $fh = $this->ffi->new('struct zend_file_handle');
        ($this->zend_stream_init)(FFI::addr($fh), $file);
        ($this->php_execute_script)(FFI::addr($fh));
        ($this->zend_destroy_fh)(FFI::addr($fh));

        ($this->php_request_shutdown)(null);
    }

    private function deinitPhp(): void {
        foreach ($this->free_list as $cdata) {
            FFI::free($cdata);
        }
        ($this->php_module_shutdown)();
        ($this->sapi_shutdown)();
        ($this->tsrm_shutdown)();
        $this->libc->dlclose($this->libphp);
    }

    private function initPhpModule(): void {
        $this->module = $this->ffi->new('struct sapi_module_struct');
        FFI::memset(FFI::addr($this->module), 0, FFI::sizeof($this->module));
        $this->module->name        = $this->cString('ffi-zts');
        $this->module->pretty_name = $this->cString('ffi-zts meta-SAPI');
        $this->module->startup     = function ($module) {
            $ini = $this->buildIniEntries();
            $this->module->ini_entries = $this->cString($ini);
            return ($this->php_module_startup)($module, null);
        };
        $this->module->shutdown                  = $this->module_shutdown_wrap;
        $this->module->ub_write                  = function (string $str, int $len) {
            echo $str;
            return $len;
        };
        $this->module->sapi_error                = function ($type, $fmt) {
            fwrite(STDERR, "[ffi-zts sapi_error $type] $fmt\n");
        };
        $this->module->send_header               = function ($h, $c) {};
        $this->module->read_cookies              = function () { return null; };
        $this->module->register_server_variables = function ($a) {};
        $this->module->log_message               = function ($msg, $type) {
            fwrite(STDERR, "[ffi-zts log $type] $msg\n");
        };
    }

    private function buildIniEntries(): string {
        $base = <<<EOD
        html_errors=0
        implicit_flush=1
        output_buffering=0
        max_execution_time=0
        max_input_time=-1
        display_errors=1
        display_startup_errors=1
        EOD;
        if ($this->ini_path !== null && is_readable($this->ini_path)) {
            $base .= "\n" . file_get_contents($this->ini_path);
        }
        return $base;
    }

    private function cString(string $s): FFI\CData {
        $slen  = strlen($s);
        $cdata = $this->ffi->new(sprintf('char[%d]', $slen + 1), false);
        FFI::memset(FFI::addr($cdata), 0, $slen + 1);
        FFI::memcpy($cdata, $s, $slen);
        $this->free_list[] = $cdata;
        return $cdata;
    }

    private function getCDefs(): string {
        return <<<EOD
            struct zend_file_handle {
                uint8_t opaque[80];
            };
            struct sapi_module_struct {
                char *name;
                char *pretty_name;
                int (*startup)(void *);
                int (*shutdown)(void *);
                int (*activate)(void);
                int (*deactivate)(void);
                size_t (*ub_write)(const char *, size_t);
                void (*flush)(void *);
                void *(*get_stat)(void);
                char *(*getenv)(const char *, size_t);
                void (*sapi_error)(int, const char *);
                int (*header_handler)(void *, int, void *);
                int (*send_headers)(void *);
                void (*send_header)(void *, void *);
                size_t (*read_post)(char *, size_t);
                char *(*read_cookies)(void);
                void (*register_server_variables)(void *);
                void (*log_message)(const char *, int);
                void (*get_request_time)(double *);
                void (*terminate_process)(void);
                char *php_ini_path_override;
                void (*default_post_reader)(void);
                void (*treat_data)(int, char *, void *);
                char *executable_location;
                int php_ini_ignore;
                int php_ini_ignore_cwd;
                int (*get_fd)(int *);
                int (*force_http_10)(void);
                int (*get_target_uid)(void *);
                int (*get_target_gid)(void *);
                unsigned int (*input_filter)(int, const char *, char **, size_t, size_t *);
                void (*ini_defaults)(void *);
                int phpinfo_as_text;
                const char *ini_entries;
                const void *additional_functions;
                unsigned int (*input_filter_init)(void);
            };
        EOD;
    }
})->run();
