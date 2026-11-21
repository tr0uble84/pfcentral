<?php

namespace App\Services\Upgrades;

/** What the upgrade centre needs from one firewall. */
interface FirmwareDriver
{
    /**
     * Facts for the pre-checks. Throws when the firewall's API doesn't answer.
     *
     * @return array{version: string, disk_free_kb: ?int, backups: ?int, zfs: bool, maker: string, product: string, pf: string, running: bool, boot: ?int}
     */
    public function facts(): array;

    /** @return array{version: ?string, up_to_date: bool, output: string} the newer version the firewall offers, if any */
    public function available(): array;

    /** The firewall's config.xml. */
    public function backup(): string;

    /** Start the upgrade in the background on the firewall (it reboots by itself when done). */
    public function start(): void;

    /** The last lines of the upgrade's log on the firewall. */
    public function log(): string;

    /** Does the firewall answer on its web address at all (even when its API doesn't)? */
    public function reachable(): bool;

    /** Can reinstallApi() be used (SSH credentials and a verified host key are set)? */
    public function canReinstallApi(): bool;

    /** Reinstall the REST API package over SSH after an upgrade removed it. Returns the command's output. */
    public function reinstallApi(): string;
}
