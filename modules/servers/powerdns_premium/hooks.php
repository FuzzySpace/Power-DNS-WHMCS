<?php

/**
 * PowerDNS Premium – WHMCS Hooks
 *
 * Registers:
 *   1. ClientAreaPage AJAX handler  – all client-facing record operations
 *   2. DailyCronJob                 – audit log pruning
 *   3. AdminAreaHeadOutput          – injects CSS for admin area records page
 *
 * Deploy: copy to <whmcs>/includes/hooks/powerdns_premium_hooks.php
 * (Or autoload from the module by adding require_once at the bottom of
 *  powerdns_premium.php once you confirm the path.)
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Guard: prevents double-registration when WHMCS auto-loads this file and
// powerdns_premium.php also require_once's it.
if (defined('PDNS_P_HOOKS_LOADED')) {
    return;
}
define('PDNS_P_HOOKS_LOADED', true);

use WHMCS\Database\Capsule;

$_pdns_p_moduleDir = dirname(__FILE__);
require_once $_pdns_p_moduleDir . '/lib/PowerDNSAPI.php';
require_once $_pdns_p_moduleDir . '/lib/LicenseManager.php';
require_once $_pdns_p_moduleDir . '/lib/AuditLog.php';
require_once $_pdns_p_moduleDir . '/lib/PropagationChecker.php';
require_once $_pdns_p_moduleDir . '/lib/ZoneIO.php';
require_once $_pdns_p_moduleDir . '/lib/RecordTemplates.php';

// =============================================================================
// 1. Client Area AJAX handler
// =============================================================================

add_hook('ClientAreaPage', 1, function ($vars) {

    if (empty($_POST['pdns_p_ajax']) || empty($_POST['service_id']) || empty($_POST['pdns_action'])) {
        return;
    }

    header('Content-Type: application/json');

    // CSRF
    try {
        check_token('WHMCS.clientarea');
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Invalid security token. Refresh the page.']);
        exit;
    }

    $serviceId = (int) $_POST['service_id'];
    $userId    = (int) ($_SESSION['uid'] ?? 0);

    // Verify service belongs to logged-in client
    $service = Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->where('userid', $userId)
        ->first();

    if (!$service) {
        echo json_encode(['success' => false, 'error' => 'Service not found or access denied.']);
        exit;
    }

    if ($service->domainstatus !== 'Active') {
        echo json_encode(['success' => false, 'error' => 'Service is not active.']);
        exit;
    }

    // Build API client
    $apiClient = _pdns_p_buildApiFromService($service);
    if (!$apiClient) {
        echo json_encode(['success' => false, 'error' => 'Server configuration not found.']);
        exit;
    }

    [$api, $moduleConfig] = $apiClient;
    // Normalize zone name; convert IDN to punycode when intl is available
    $zonePlain  = strtolower(trim($service->domain));
    $zone       = (extension_loaded('intl') && function_exists('idn_to_ascii'))
        ? (idn_to_ascii($zonePlain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $zonePlain)
        : $zonePlain;
    $defaultTTL = (int) ($moduleConfig['configoption4'] ?? 300);
    $maxRecords = (int) ($moduleConfig['configoption6'] ?? 0);
    $allowDNSSEC = !empty($moduleConfig['configoption7']);
    $action     = $_POST['pdns_action'];

    // License check (skip for read-only / propagation actions)
    $readonlyActions = ['check_propagation', 'get_audit', 'export_zone'];
    $licenseKey = trim($moduleConfig['configoption8'] ?? '');
    $license = new LicenseManager($licenseKey);
    if (!$license->isValid() && !in_array($action, $readonlyActions, true)) {
        echo json_encode(['success' => false, 'error' => 'Module license is invalid. Record management is disabled.']);
        exit;
    }

    // Dispatch
    try {
        switch ($action) {

            // ----------------------------------------------------------------
            case 'add_record':
                if ($maxRecords > 0 && $api->getRecordCount($zone) >= $maxRecords) {
                    throw new RuntimeException("Record quota of {$maxRecords} reached for this zone.");
                }
                $type    = strtoupper(trim($_POST['record_type'] ?? ''));
                $name    = trim($_POST['record_name'] ?? '');
                $ttl     = max(60, (int) ($_POST['record_ttl'] ?? $defaultTTL));

                // Normalize zone-apex shorthands
                if ($name === '@') {
                    $name = '';
                }

                $content  = _pdns_p_hooks_buildContent($type, $_POST);
                $api->addRecord($zone, $name ?: $zone, $type, $content, $ttl);
                AuditLog::write($serviceId, $userId, AuditLog::ACTION_ADD, $name ?: $zone, $type, $content, $ttl);

                // Compute the FQDN the same way PowerDNS stores it
                $zoneRoot = rtrim($zone, '.') . '.';
                if ($name === '') {
                    $fqdnName = $zoneRoot;
                } elseif (strpos($name, '.') === false) {
                    $fqdnName = $name . '.' . $zoneRoot;
                } else {
                    $fqdnName = rtrim($name, '.') . '.';
                }

                echo json_encode([
                    'success' => true,
                    'record'  => ['name' => $fqdnName, 'type' => $type, 'ttl' => $ttl, 'content' => $content],
                ]);
                break;

            // ----------------------------------------------------------------
            case 'edit_record':
                $type       = strtoupper(trim($_POST['record_type'] ?? ''));
                $name       = trim($_POST['record_name'] ?? '');
                $oldContent = trim($_POST['old_content'] ?? '');
                $ttl        = max(60, (int) ($_POST['record_ttl'] ?? $defaultTTL));
                $newContent = _pdns_p_hooks_buildContent($type, $_POST);
                $api->editRecord($zone, $name, $type, $oldContent, $newContent, $ttl);
                AuditLog::write($serviceId, $userId, AuditLog::ACTION_EDIT, $name, $type, $newContent, $ttl, "old: {$oldContent}");
                echo json_encode([
                    'success'    => true,
                    'newContent' => $newContent,
                    'newTTL'     => $ttl,
                ]);
                break;

            // ----------------------------------------------------------------
            case 'delete_record':
                $type    = strtoupper(trim($_POST['record_type'] ?? ''));
                $name    = trim($_POST['record_name'] ?? '');
                $content = trim($_POST['record_content'] ?? '');
                $api->deleteRecord($zone, $name, $type, $content);
                AuditLog::write($serviceId, $userId, AuditLog::ACTION_DELETE, $name, $type, $content);
                echo json_encode(['success' => true]);
                break;

            // ----------------------------------------------------------------
            case 'toggle_dnssec':
                if (!$allowDNSSEC) throw new RuntimeException('DNSSEC management is not enabled for this product.');
                $enable = !empty($_POST['dnssec_enable']);
                if ($enable) {
                    $api->enableDNSSEC($zone);
                    AuditLog::write($serviceId, $userId, AuditLog::ACTION_DNSSEC, $zone, 'DNSSEC', 'enabled');
                    $dsRecords = $api->getDSRecords($zone);
                    echo json_encode(['success' => true, 'enabled' => true, 'dsRecords' => $dsRecords]);
                } else {
                    $api->disableDNSSEC($zone);
                    AuditLog::write($serviceId, $userId, AuditLog::ACTION_DNSSEC, $zone, 'DNSSEC', 'disabled');
                    echo json_encode(['success' => true, 'enabled' => false, 'dsRecords' => []]);
                }
                break;

            // ----------------------------------------------------------------
            case 'apply_template':
                $templateId = trim($_POST['template_id'] ?? '');
                $tpl        = RecordTemplates::get($templateId, $zone);
                $zoneData   = $api->getZone($zone);
                $deleteRR   = RecordTemplates::buildDeleteRRsets($zoneData['rrsets'] ?? [], $zone, $tpl['delete_types']);
                $addRR      = RecordTemplates::toRRsets($tpl['records']);
                if (!empty($deleteRR)) $api->batchPatch($zone, $deleteRR);
                if (!empty($addRR))    $api->batchPatch($zone, $addRR);
                AuditLog::write($serviceId, $userId, AuditLog::ACTION_TEMPLATE, $zone, 'TEMPLATE', $templateId);
                // Return refreshed flat records
                $freshRecords = _pdns_p_flatRecords($api, $zone);
                echo json_encode(['success' => true, 'records' => $freshRecords]);
                break;

            // ----------------------------------------------------------------
            case 'check_propagation':
                $checkName = trim($_POST['check_name'] ?? $zone);
                $checkType = strtoupper(trim($_POST['check_type'] ?? 'A'));
                $results   = PropagationChecker::check($checkName, $checkType);
                echo json_encode(['success' => true, 'results' => $results]);
                break;

            // ----------------------------------------------------------------
            case 'import_csv':
                if (empty($_FILES['csv_file']['tmp_name'])) {
                    throw new RuntimeException('No file uploaded.');
                }
                $csv    = file_get_contents($_FILES['csv_file']['tmp_name']);
                $parsed = ZoneIO::parseCSV($csv, $zone, PowerDNSAPI::$SUPPORTED_TYPES);
                if (!empty($parsed['errors']) && empty($parsed['records'])) {
                    echo json_encode(['success' => false, 'error' => implode('; ', array_column($parsed['errors'], 'message'))]);
                    break;
                }
                $rrsets = ZoneIO::toRRsets($parsed['records']);
                $api->batchPatch($zone, $rrsets);
                AuditLog::writeBatch($serviceId, $userId, AuditLog::ACTION_IMPORT, $parsed['records'], 'CSV import');
                $freshRecords = _pdns_p_flatRecords($api, $zone);
                echo json_encode([
                    'success' => true,
                    'imported' => count($parsed['records']),
                    'errors'  => $parsed['errors'],
                    'records' => $freshRecords,
                ]);
                break;

            // ----------------------------------------------------------------
            case 'export_zone':
                $format   = strtolower(trim($_POST['format'] ?? 'csv'));
                $zoneData = $api->getZone($zone);
                if ($format === 'bind') {
                    $content  = ZoneIO::exportBIND($zone, $zoneData);
                    $filename = str_replace('.', '_', rtrim($zone, '.')) . '.zone';
                    $mime     = 'text/plain';
                } else {
                    $content  = ZoneIO::exportCSV($zone, $zoneData);
                    $filename = str_replace('.', '_', rtrim($zone, '.')) . '_records.csv';
                    $mime     = 'text/csv';
                }
                echo json_encode(['success' => true, 'content' => base64_encode($content), 'filename' => $filename, 'mime' => $mime]);
                break;

            // ----------------------------------------------------------------
            case 'get_audit':
                $page    = max(1, (int) ($_POST['page'] ?? 1));
                $perPage = 20;
                $offset  = ($page - 1) * $perPage;
                $entries = AuditLog::forService($serviceId, $perPage, $offset);
                $total   = AuditLog::countForService($serviceId);
                echo json_encode(['success' => true, 'entries' => $entries, 'total' => $total, 'page' => $page, 'perPage' => $perPage]);
                break;

            // ----------------------------------------------------------------
            default:
                echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        }
    } catch (Exception $e) {
        logActivity('PowerDNS Premium AJAX error [' . $action . '] zone=' . $zone . ': ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

    exit;
});

// =============================================================================
// 2. Daily cron – prune old audit entries
// =============================================================================

add_hook('DailyCronJob', 1, function () {
    AuditLog::purgeOlderThan(365);
});

// =============================================================================
// Internal helpers
// =============================================================================

/**
 * Build API client + config array from a tblhosting row.
 *
 * @return array|null  [PowerDNSAPI, moduleConfigArray] or null on failure
 */
function _pdns_p_buildApiFromService($service)
{
    try {
        $server = Capsule::table('tblservers')->where('id', $service->serverid)->first();
        if (!$server) return null;

        $product = Capsule::table('tblproducts')->where('id', $service->packageid)->first();
        $cfg = [];
        if ($product) {
            for ($i = 1; $i <= 8; $i++) {
                $cfg["configoption{$i}"] = $product->{"configoption{$i}"} ?? '';
            }
        }

        $scheme   = $server->secure ? 'https' : 'http';
        $port     = $server->port ?: 8081;
        $host     = trim((string) ($server->hostname ?: $server->ipaddress));
        $baseUrl  = "{$scheme}://{$host}:{$port}";
        $serverId = trim($cfg['configoption5'] ?? 'localhost');

        // Decrypt API key
        $apiKey = \WHMCS\Crypt\AES::decrypt($server->password);

        return [new PowerDNSAPI($baseUrl, $apiKey, $serverId), $cfg];
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Return a flat array of active (non-disabled) records for templating.
 */
function _pdns_p_flatRecords(PowerDNSAPI $api, $zone)
{
    $flat = [];
    try {
        $rrsets = $api->getRecords($zone, PowerDNSAPI::$SUPPORTED_TYPES);
        foreach ($rrsets as $rr) {
            foreach ($rr['records'] as $rec) {
                if ($rec['disabled'] ?? false) continue;
                $flat[] = ['name' => $rr['name'], 'type' => $rr['type'], 'ttl' => $rr['ttl'], 'content' => $rec['content']];
            }
        }
    } catch (Exception $e) { /* return empty */ }
    return $flat;
}

/**
 * Content builder – mirrors powerdns_premium.php logic (shared via function).
 * Kept here so hooks.php is self-contained.
 */
function _pdns_p_hooks_buildContent($type, array $post)
{
    switch (strtoupper($type)) {
        case 'A':
            $ip = trim($post['record_value'] ?? '');
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) throw new InvalidArgumentException("Invalid IPv4: {$ip}");
            return $ip;
        case 'AAAA':
            $ip = trim($post['record_value'] ?? '');
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) throw new InvalidArgumentException("Invalid IPv6: {$ip}");
            return $ip;
        case 'CNAME': case 'ALIAS': case 'NS': case 'PTR':
            $t = rtrim(trim($post['record_value'] ?? ''), '.') . '.';
            if (strlen(rtrim($t, '.')) < 1) throw new InvalidArgumentException("Target is required.");
            return $t;
        case 'MX':
            $p = max(0, (int) ($post['record_priority'] ?? 10));
            $t = rtrim(trim($post['record_value'] ?? ''), '.') . '.';
            if (strlen(rtrim($t, '.')) < 1) throw new InvalidArgumentException("MX target is required.");
            return "{$p} {$t}";
        case 'TXT':
            $v = trim($post['record_value'] ?? '');
            if (empty($v)) throw new InvalidArgumentException("TXT content is required.");
            if ($v[0] !== '"') $v = '"' . str_replace('"', '\\"', $v) . '"';
            return $v;
        case 'SRV':
            $p    = max(0, (int) ($post['srv_priority'] ?? 10));
            $w    = max(0, (int) ($post['srv_weight']   ?? 0));
            $port = (int) ($post['srv_port'] ?? 0);
            $t    = rtrim(trim($post['record_value'] ?? ''), '.') . '.';
            if ($port < 1 || $port > 65535) throw new InvalidArgumentException("SRV port must be 1–65535.");
            return "{$p} {$w} {$port} {$t}";
        case 'CAA':
            $flag = (int) ($post['caa_flag'] ?? 0);
            $tag  = trim($post['caa_tag']    ?? 'issue');
            $val  = trim($post['record_value'] ?? '');
            if (!in_array($tag, ['issue','issuewild','iodef'], true)) throw new InvalidArgumentException("Invalid CAA tag.");
            if (empty($val)) throw new InvalidArgumentException("CAA value is required.");
            if ($val[0] !== '"') $val = '"' . $val . '"';
            return "{$flag} {$tag} {$val}";
        default:
            throw new InvalidArgumentException("Unsupported type: {$type}");
    }
}
