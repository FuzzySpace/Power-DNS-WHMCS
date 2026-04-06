<?php

/**
 * PowerDNS WHMCS Module – Client Area AJAX Hook
 *
 * Registers a ClientAreaPage hook that intercepts requests to
 *   index.php?m=powerdns&ajax=1
 * and processes add/delete record actions, returning JSON so the
 * client area template can update the record table without a full
 * page reload.
 *
 * Drop this file in <whmcs>/includes/hooks/ or auto-load it from the
 * module by adding the following line at the bottom of powerdns.php:
 *   require_once __DIR__ . '/hooks.php';
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Guard: prevents the hook from being registered twice when WHMCS auto-loads
// this file AND powerdns.php also require_once's it.
if (defined('PDNS_HOOKS_LOADED')) {
    return;
}
define('PDNS_HOOKS_LOADED', true);

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../modules/servers/powerdns/lib/PowerDNSAPI.php';

add_hook('ClientAreaPage', 1, function ($vars) {

    // Only handle requests explicitly flagged as AJAX calls for this module
    if (
        empty($_POST['powerdns_ajax'])
        || empty($_POST['service_id'])
        || empty($_POST['powerdns_action'])
    ) {
        return;
    }

    // Validate CSRF token
    try {
        check_token('WHMCS.clientarea');
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh the page.']);
        exit;
    }

    $serviceId = (int) $_POST['service_id'];

    // Load service and verify it belongs to the logged-in client
    $service = Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->where('userid', (int) ($_SESSION['uid'] ?? 0))
        ->first();

    if (!$service) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Service not found or access denied.']);
        exit;
    }

    if ($service->domainstatus !== 'Active') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Service is not active.']);
        exit;
    }

    // Load the server record that owns this service
    $server = Capsule::table('tblservers')
        ->where('id', $service->serverid)
        ->first();

    if (!$server) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Server configuration not found.']);
        exit;
    }

    // Read module config options from the product
    $product = Capsule::table('tblproducts')
        ->where('id', $service->packageid)
        ->first();

    $moduleConfig = [];
    if ($product && $product->configoption1) {
        $moduleConfig = [
            'configoption1' => $product->configoption1,
            'configoption2' => $product->configoption2,
            'configoption3' => $product->configoption3,
            'configoption4' => $product->configoption4,
            'configoption5' => $product->configoption5,
        ];
    }

    // Build API client
    $scheme   = $server->secure ? 'https' : 'http';
    $port     = $server->port ?: 8081;
    $baseUrl  = "{$scheme}://{$server->hostname}:{$port}";
    $serverId = trim($moduleConfig['configoption5'] ?? 'localhost');

    // Decrypt server password (WHMCS uses AES encryption)
    $apiKey = \WHMCS\Crypt\AES::decrypt($server->password);

    $api  = new PowerDNSAPI($baseUrl, $apiKey, $serverId);
    // Normalize zone name; convert IDN to punycode when intl is available
    $zonePlain = strtolower(trim($service->domain));
    $zone = (extension_loaded('intl') && function_exists('idn_to_ascii'))
        ? (idn_to_ascii($zonePlain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $zonePlain)
        : $zonePlain;

    $defaultTTL  = (int) ($moduleConfig['configoption4'] ?? 300);
    $action      = $_POST['powerdns_action'];

    header('Content-Type: application/json');

    try {
        switch ($action) {

            case 'add_record':
                $type    = strtoupper(trim($_POST['record_type'] ?? ''));
                $name    = trim($_POST['record_name'] ?? '');
                $ttl     = max(60, (int) ($_POST['record_ttl'] ?? $defaultTTL));

                if (!in_array($type, ['A', 'AAAA', 'MX', 'TXT', 'SRV'], true)) {
                    throw new InvalidArgumentException("Unsupported record type: {$type}");
                }

                // Normalize zone-apex shorthands before building content and FQDN
                if ($name === '@') {
                    $name = '';
                }

                $content  = _pdns_ajax_buildContent($type, $_POST);
                $api->addRecord($zone, $name ?: $zone, $type, $content, $ttl, true);

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
                    'record'  => [
                        'name'    => $fqdnName,
                        'type'    => $type,
                        'ttl'     => $ttl,
                        'content' => $content,
                    ],
                ]);
                break;

            case 'delete_record':
                $type    = strtoupper(trim($_POST['record_type'] ?? ''));
                $name    = trim($_POST['record_name'] ?? '');
                $content = trim($_POST['record_content'] ?? '');
                $api->deleteRecord($zone, $name, $type, $content);
                echo json_encode(['success' => true]);
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        }
    } catch (Exception $e) {
        logActivity('PowerDNS AJAX error [' . $action . '] zone=' . $zone . ': ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

    exit;
});

// ---------------------------------------------------------------------------
// Content builder (mirrors powerdns.php logic; kept here to avoid circular dep)
// ---------------------------------------------------------------------------

function _pdns_ajax_buildContent($type, array $post)
{
    switch ($type) {
        case 'A':
        case 'AAAA':
            $ip = trim($post['record_value'] ?? '');
            if (empty($ip)) {
                throw new InvalidArgumentException("IP address is required.");
            }
            if ($type === 'A' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException("Invalid IPv4 address: {$ip}");
            }
            if ($type === 'AAAA' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException("Invalid IPv6 address: {$ip}");
            }
            return $ip;

        case 'MX':
            $priority = (int) ($post['record_priority'] ?? 10);
            $target   = rtrim(trim($post['record_value'] ?? ''), '.') . '.';
            if (empty(rtrim($target, '.'))) {
                throw new InvalidArgumentException("MX target is required.");
            }
            return "{$priority} {$target}";

        case 'TXT':
            $value = trim($post['record_value'] ?? '');
            if (empty($value)) {
                throw new InvalidArgumentException("TXT content is required.");
            }
            if (substr($value, 0, 1) !== '"') {
                $value = '"' . addslashes($value) . '"';
            }
            return $value;

        case 'SRV':
            $priority = (int) ($post['srv_priority'] ?? 10);
            $weight   = (int) ($post['srv_weight']   ?? 0);
            $port     = (int) ($post['srv_port']     ?? 0);
            $target   = rtrim(trim($post['record_value'] ?? ''), '.') . '.';
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException("SRV port must be between 1 and 65535.");
            }
            if (empty(rtrim($target, '.'))) {
                throw new InvalidArgumentException("SRV target is required.");
            }
            return "{$priority} {$weight} {$port} {$target}";

        default:
            throw new InvalidArgumentException("Unsupported record type: {$type}");
    }
}
