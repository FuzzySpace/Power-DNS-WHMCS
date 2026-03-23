<?php

/**
 * License Manager – PowerDNS Premium
 *
 * Validates an IonCube-compatible license key against the WHMCS License API.
 * Results are cached in `tblconfiguration` for 24 hours so every page load
 * does not make an outbound request.
 *
 * The structure of this file is intentionally IonCube-encode-friendly:
 *   - No closures as property values
 *   - No late static binding in the hot path
 *   - All string literals are simple
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class LicenseManager
{
    const CACHE_KEY     = 'mod_powerdns_premium_license';
    const CACHE_TTL     = 86400; // 24 hours
    const LICENSE_API   = 'https://whmcs.com/api.php';       // replace with your own license server
    const PRODUCT_ID    = 'powerdns_premium';

    /** @var string */
    private $licenseKey;

    /** @var string  WHMCS installation URL (used as identifier) */
    private $whmcsUrl;

    public function __construct($licenseKey, $whmcsUrl = '')
    {
        $this->licenseKey = trim($licenseKey);
        $this->whmcsUrl   = $whmcsUrl ?: (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : '');
    }

    // -------------------------------------------------------------------------

    /**
     * Returns true if the license is valid (or grace-period active).
     * Returns false if invalid, expired, or key is empty.
     *
     * Caches result to avoid hammering the license server.
     */
    public function isValid()
    {
        if (empty($this->licenseKey)) {
            return false;
        }

        $cached = $this->loadCache();
        if ($cached !== null) {
            return $cached['valid'] === true;
        }

        $result = $this->checkRemote();
        $this->saveCache($result);

        return $result['valid'] === true;
    }

    /**
     * Return a human-readable status string for display in the admin panel.
     */
    public function getStatus()
    {
        if (empty($this->licenseKey)) {
            return 'No license key configured.';
        }

        $cached = $this->loadCache();
        $result = $cached ?? $this->checkRemote();
        if ($cached === null) {
            $this->saveCache($result);
        }

        if ($result['valid']) {
            $expiry = !empty($result['expires']) ? ' (expires ' . $result['expires'] . ')' : '';
            return 'License valid' . $expiry . '.';
        }

        return 'License invalid: ' . ($result['message'] ?? 'Unknown error') . '.';
    }

    /**
     * Force-clear the local cache (useful after updating the license key).
     */
    public function clearCache()
    {
        try {
            Capsule::table('tblconfiguration')
                ->where('setting', self::CACHE_KEY)
                ->delete();
        } catch (Exception $e) {
            // ignore
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Call the remote license server.
     *
     * Returns array with at least: ['valid' => bool, 'message' => string]
     */
    private function checkRemote()
    {
        try {
            $post = http_build_query([
                'action'      => 'validate',
                'product'     => self::PRODUCT_ID,
                'licensekey'  => $this->licenseKey,
                'domain'      => parse_url($this->whmcsUrl, PHP_URL_HOST) ?: $this->whmcsUrl,
                'phpversion'  => PHP_VERSION,
            ]);

            $ch = curl_init(self::LICENSE_API);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $post,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || empty($response)) {
                // Network error – grant a short grace period rather than hard-blocking
                return ['valid' => true, 'message' => 'License server unreachable; operating in grace period.', 'expires' => ''];
            }

            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['valid' => true, 'message' => 'Invalid response from license server; operating in grace period.', 'expires' => ''];
            }

            return [
                'valid'   => isset($data['result']) && $data['result'] === 'Active',
                'message' => $data['message'] ?? '',
                'expires' => $data['expires'] ?? '',
            ];

        } catch (Exception $e) {
            return ['valid' => true, 'message' => 'Exception checking license: ' . $e->getMessage(), 'expires' => ''];
        }
    }

    /**
     * Load cached result from tblconfiguration.
     * Returns null if no valid cache exists.
     */
    private function loadCache()
    {
        try {
            $row = Capsule::table('tblconfiguration')
                ->where('setting', self::CACHE_KEY)
                ->first();

            if (!$row) {
                return null;
            }

            $data = json_decode($row->value, true);
            if (!$data || empty($data['cached_at'])) {
                return null;
            }

            if ((time() - $data['cached_at']) > self::CACHE_TTL) {
                return null; // expired
            }

            return $data;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Persist result to tblconfiguration with a timestamp.
     */
    private function saveCache(array $result)
    {
        $result['cached_at'] = time();
        $value = json_encode($result);

        try {
            $exists = Capsule::table('tblconfiguration')
                ->where('setting', self::CACHE_KEY)
                ->count();

            if ($exists) {
                Capsule::table('tblconfiguration')
                    ->where('setting', self::CACHE_KEY)
                    ->update(['value' => $value]);
            } else {
                Capsule::table('tblconfiguration')
                    ->insert(['setting' => self::CACHE_KEY, 'value' => $value]);
            }
        } catch (Exception $e) {
            // non-fatal
        }
    }
}
