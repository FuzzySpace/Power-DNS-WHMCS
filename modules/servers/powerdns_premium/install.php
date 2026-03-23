<?php

/**
 * PowerDNS Premium – Database Installer
 *
 * Creates the required tables if they don't already exist.
 *
 * Usage:
 *   a) Navigate to this file directly once in a browser:
 *      https://your-whmcs.com/modules/servers/powerdns_premium/install.php
 *
 *   b) Call powerdns_premium_install() from an activation hook.
 *
 * The script is idempotent – running it multiple times is safe.
 */

// Allow direct browser access (with a simple guard) OR WHMCS context
if (!defined('WHMCS')) {
    // Direct browser invocation: bootstrap just enough of WHMCS to use Capsule
    $whmcsRoot = dirname(dirname(dirname(dirname(__FILE__)))); // four levels up
    $initFile  = $whmcsRoot . '/init.php';

    if (!file_exists($initFile)) {
        die('<b>Error:</b> Could not locate WHMCS init.php. '
          . 'Ensure this file is installed at modules/servers/powerdns_premium/install.php '
          . 'within your WHMCS root.');
    }

    define('WHMCS', true);
    require_once $initFile;
}

use WHMCS\Database\Capsule;

/**
 * Run all migrations.
 *
 * @return array  ['success' => bool, 'messages' => string[]]
 */
function powerdns_premium_install()
{
    $messages = [];

    // -------------------------------------------------------------------------
    // Table: mod_powerdns_audit
    // -------------------------------------------------------------------------
    try {
        if (!Capsule::schema()->hasTable('mod_powerdns_audit')) {
            Capsule::schema()->create('mod_powerdns_audit', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('service_id');
                $table->unsignedInteger('user_id');
                $table->enum('action', ['add', 'edit', 'delete', 'template', 'import', 'dnssec']);
                $table->string('record_name', 255);
                $table->string('record_type', 10);
                $table->text('record_content');
                $table->unsignedInteger('ttl')->default(0);
                $table->string('note', 500)->default('');
                $table->string('ip_address', 45)->default('');
                $table->dateTime('created_at');
                $table->index('service_id');
                $table->index('user_id');
            });
            $messages[] = '✓ Created table: mod_powerdns_audit';
        } else {
            $messages[] = '– Table already exists: mod_powerdns_audit';
        }
    } catch (Exception $e) {
        return ['success' => false, 'messages' => ["✗ Failed to create mod_powerdns_audit: " . $e->getMessage()]];
    }

    // -------------------------------------------------------------------------
    // Table: mod_powerdns_quota  (soft cache of record counts per service)
    // -------------------------------------------------------------------------
    try {
        if (!Capsule::schema()->hasTable('mod_powerdns_quota')) {
            Capsule::schema()->create('mod_powerdns_quota', function ($table) {
                $table->unsignedInteger('service_id')->primary();
                $table->unsignedInteger('record_count')->default(0);
                $table->unsignedInteger('max_records')->default(0);
                $table->dateTime('updated_at');
            });
            $messages[] = '✓ Created table: mod_powerdns_quota';
        } else {
            $messages[] = '– Table already exists: mod_powerdns_quota';
        }
    } catch (Exception $e) {
        return ['success' => false, 'messages' => ["✗ Failed to create mod_powerdns_quota: " . $e->getMessage()]];
    }

    // -------------------------------------------------------------------------
    // WHMCS Email Template: zone created notification
    // -------------------------------------------------------------------------
    try {
        $exists = Capsule::table('tblemailTemplates')
            ->where('name', 'powerdns_premium_zone_created')
            ->count();

        if (!$exists) {
            Capsule::table('tblemailTemplates')->insert([
                'type'     => 'product',
                'name'     => 'powerdns_premium_zone_created',
                'subject'  => 'Your DNS Zone Has Been Created',
                'message'  => "<p>Hello {\$client_name},</p>\n"
                            . "<p>Your DNS zone for <strong>{\$service_domain}</strong> has been successfully created "
                            . "on our authoritative nameservers.</p>\n"
                            . "<p>You can manage your DNS records at any time from the <a href=\"{\$whmcs_url}/clientarea.php?action=productdetails&id={\$service_id}\">Client Area</a>.</p>\n"
                            . "<p>Please point your domain to the following nameservers:</p>\n"
                            . "<ul><li>ns1.example.com</li><li>ns2.example.com</li></ul>\n"
                            . "<p>Thank you for choosing our services!</p>",
                'disabled' => 0,
            ]);
            $messages[] = '✓ Created email template: powerdns_premium_zone_created';
        } else {
            $messages[] = '– Email template already exists: powerdns_premium_zone_created';
        }
    } catch (Exception $e) {
        $messages[] = '– Could not create email template (non-fatal): ' . $e->getMessage();
    }

    return ['success' => true, 'messages' => $messages];
}

// -------------------------------------------------------------------------
// Run if accessed directly
// -------------------------------------------------------------------------
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'install.php') {
    $result = powerdns_premium_install();
    $color  = $result['success'] ? '#2ecc71' : '#e74c3c';
    echo '<!DOCTYPE html><html><head><title>PowerDNS Premium Installer</title>'
       . '<style>body{font-family:monospace;background:#1a1a2e;color:#eee;padding:2em;} '
       . 'h1{color:#00d4ff;} ul{line-height:2;} .ok{color:#2ecc71;} .err{color:#e74c3c;}</style></head><body>';
    echo '<h1>PowerDNS Premium – Installer</h1>';
    foreach ($result['messages'] as $msg) {
        $cls = (strpos($msg, '✗') === 0) ? 'err' : 'ok';
        echo "<p class=\"{$cls}\">{$msg}</p>";
    }
    $status = $result['success'] ? 'Installation complete.' : 'Installation encountered errors.';
    echo "<h2 style=\"color:{$color}\">{$status}</h2>";
    echo '<p>You may now delete this file or restrict access to it.</p>';
    echo '</body></html>';
}
