<?php
declare(strict_types=1);

namespace SjI\FfiZts;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * Composer plugin that fetches the per-host libphp.so artefact
 * when sj-i/ffi-zts itself is installed or updated.
 *
 * Library-side Composer `scripts` (post-install-cmd, ...) do not
 * run in consumer projects, so the previous scripts-based hook
 * never fired on `composer require sj-i/ffi-zts`. `composer-plugin`
 * is the intended mechanism: Composer loads this class during its
 * own run, the class subscribes to POST_PACKAGE_INSTALL /
 * POST_PACKAGE_UPDATE events, and when the event is for THIS
 * package it delegates to SjI\FfiZts\Installer::fetchBinaries.
 *
 * The plugin intentionally ignores events for any package other
 * than sj-i/ffi-zts: the satellite package sj-i/ffi-zts-parallel
 * ships its own plugin and handles its own binaries the same way.
 */
final class ComposerPlugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'sj-i/ffi-zts';

    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPackageInstallOrUpdate',
            PackageEvents::POST_PACKAGE_UPDATE  => 'onPackageInstallOrUpdate',
        ];
    }

    public function onPackageInstallOrUpdate(PackageEvent $event): void
    {
        $op = $event->getOperation();
        $pkg = match (true) {
            $op instanceof InstallOperation => $op->getPackage(),
            $op instanceof UpdateOperation  => $op->getTargetPackage(),
            default                         => null,
        };
        if ($pkg === null || $pkg->getName() !== self::PACKAGE_NAME) {
            return;
        }
        try {
            Installer::fetchBinaries($event);
        } catch (\Throwable $e) {
            // Don't fail the entire composer run on a binary-fetch
            // hiccup (network outage, Release not yet published for
            // a new PHP minor, etc.). The user can recover with
            // `vendor/bin/ffi-zts install` once the underlying issue
            // is resolved.
            $this->io->writeError(
                "<warning>sj-i/ffi-zts: binary fetch failed: {$e->getMessage()}</warning>",
            );
            $this->io->writeError(
                '<warning>sj-i/ffi-zts: run `vendor/bin/ffi-zts install` to retry</warning>',
            );
        }
    }
}
