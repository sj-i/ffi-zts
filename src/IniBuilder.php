<?php
declare(strict_types=1);

namespace SjI\FfiZts;

final class IniBuilder
{
    public static function build(Config $config): string
    {
        $lines = [
            'html_errors=0',
            'implicit_flush=1',
            'output_buffering=0',
            'max_execution_time=0',
            'max_input_time=-1',
            'display_errors=1',
            'display_startup_errors=1',
        ];
        if ($config->extensionDir !== null) {
            $lines[] = 'extension_dir=' . $config->extensionDir;
        }
        foreach ($config->extensions as $ext) {
            $key = $ext->isZendExtension ? 'zend_extension' : 'extension';
            $lines[] = "{$key}={$ext->path}";
        }
        foreach ($config->iniEntries as $k => $v) {
            $lines[] = "{$k}={$v}";
        }
        $body = implode("\n", $lines);
        if ($config->iniPath !== null && is_readable($config->iniPath)) {
            $body .= "\n" . file_get_contents($config->iniPath);
        }
        return $body;
    }

    public static function sapiCdefFragment(): string
    {
        // Layout must match PHP 8.5's main/SAPI.h:_sapi_module_struct
        // byte-for-byte. Any field missing from the tail causes FFI::new
        // to allocate a short buffer; php_module_startup -> sapi_activate
        // then reads a function pointer past our allocation boundary and
        // segfaults on the first call. PHP 8.5 added `pre_request_init`
        // relative to 8.4; future minors may append more, so this block
        // is the canonical place to keep in sync.
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
                int (*get_request_time)(double *);
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
                int (*pre_request_init)(void);
            };
        EOD;
    }
}
