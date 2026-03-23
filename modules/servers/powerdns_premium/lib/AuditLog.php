<?php

/**
 * Audit Log – PowerDNS Premium
 *
 * Records every DNS record change (add, edit, delete, template apply, bulk
 * import) to the `mod_powerdns_audit` database table.
 *
 * Provides retrieval helpers for the client History tab and admin overview.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class AuditLog
{
    const TABLE = 'mod_powerdns_audit';

    // Action type constants
    const ACTION_ADD      = 'add';
    const ACTION_EDIT     = 'edit';
    const ACTION_DELETE   = 'delete';
    const ACTION_TEMPLATE = 'template';
    const ACTION_IMPORT   = 'import';
    const ACTION_DNSSEC   = 'dnssec';

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Write a single audit entry.
     *
     * @param int    $serviceId
     * @param int    $userId
     * @param string $action     One of the ACTION_* constants
     * @param string $recordName  Fully-qualified record name
     * @param string $recordType
     * @param string $content     New content (or old content for deletes)
     * @param int    $ttl
     * @param string $note        Optional free-text note (e.g. "edit: old → new")
     */
    public static function write($serviceId, $userId, $action, $recordName, $recordType, $content, $ttl = 0, $note = '')
    {
        try {
            Capsule::table(self::TABLE)->insert([
                'service_id'     => (int) $serviceId,
                'user_id'        => (int) $userId,
                'action'         => $action,
                'record_name'    => (string) $recordName,
                'record_type'    => strtoupper((string) $recordType),
                'record_content' => (string) $content,
                'ttl'            => (int) $ttl,
                'note'           => (string) $note,
                'ip_address'     => self::clientIp(),
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            // Logging must never crash the main operation
            error_log('PowerDNS Premium AuditLog::write failed: ' . $e->getMessage());
        }
    }

    /**
     * Convenience: log a bulk batch of records with the same action.
     *
     * @param int    $serviceId
     * @param int    $userId
     * @param string $action
     * @param array  $records  Each item: ['name','type','content','ttl']
     * @param string $note
     */
    public static function writeBatch($serviceId, $userId, $action, array $records, $note = '')
    {
        foreach ($records as $rec) {
            self::write(
                $serviceId,
                $userId,
                $action,
                $rec['name']    ?? '',
                $rec['type']    ?? '',
                $rec['content'] ?? '',
                $rec['ttl']     ?? 0,
                $note
            );
        }
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Fetch audit entries for a single service (client History tab).
     *
     * @param  int $serviceId
     * @param  int $limit
     * @param  int $offset
     * @return array
     */
    public static function forService($serviceId, $limit = 50, $offset = 0)
    {
        try {
            return Capsule::table(self::TABLE)
                ->where('service_id', (int) $serviceId)
                ->orderBy('id', 'desc')
                ->skip($offset)
                ->take($limit)
                ->get()
                ->map(function ($row) { return (array) $row; })
                ->toArray();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Total count of audit entries for a service (for pagination).
     */
    public static function countForService($serviceId)
    {
        try {
            return Capsule::table(self::TABLE)
                ->where('service_id', (int) $serviceId)
                ->count();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Fetch all audit entries across all services (admin overview).
     *
     * @param  int    $limit
     * @param  int    $offset
     * @param  string $search  Optional: filter by record_name LIKE %search%
     * @return array
     */
    public static function all($limit = 100, $offset = 0, $search = '')
    {
        try {
            $q = Capsule::table(self::TABLE)
                ->leftJoin('tblhosting', self::TABLE . '.service_id', '=', 'tblhosting.id')
                ->leftJoin('tblclients', self::TABLE . '.user_id',    '=', 'tblclients.id')
                ->select(
                    self::TABLE . '.*',
                    'tblhosting.domain as zone',
                    Capsule::raw("CONCAT(tblclients.firstname, ' ', tblclients.lastname) as client_name")
                );

            if ($search !== '') {
                $q->where(self::TABLE . '.record_name', 'LIKE', '%' . $search . '%');
            }

            return $q->orderBy(self::TABLE . '.id', 'desc')
                ->skip($offset)
                ->take($limit)
                ->get()
                ->map(function ($row) { return (array) $row; })
                ->toArray();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Purge entries older than $days days (called from a WHMCS daily cron hook).
     *
     * @param int $days
     */
    public static function purgeOlderThan($days = 365)
    {
        try {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            Capsule::table(self::TABLE)->where('created_at', '<', $cutoff)->delete();
        } catch (Exception $e) {
            // non-fatal
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return the real client IP, respecting common proxy headers.
     */
    private static function clientIp()
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }

    /**
     * Human-readable label for an action constant.
     */
    public static function actionLabel($action)
    {
        $map = [
            self::ACTION_ADD      => 'Added',
            self::ACTION_EDIT     => 'Edited',
            self::ACTION_DELETE   => 'Deleted',
            self::ACTION_TEMPLATE => 'Template Applied',
            self::ACTION_IMPORT   => 'Bulk Imported',
            self::ACTION_DNSSEC   => 'DNSSEC Changed',
        ];
        return $map[$action] ?? ucfirst($action);
    }

    /**
     * CSS badge class for an action (Bootstrap 3).
     */
    public static function actionClass($action)
    {
        $map = [
            self::ACTION_ADD      => 'success',
            self::ACTION_EDIT     => 'info',
            self::ACTION_DELETE   => 'danger',
            self::ACTION_TEMPLATE => 'warning',
            self::ACTION_IMPORT   => 'primary',
            self::ACTION_DNSSEC   => 'default',
        ];
        return $map[$action] ?? 'default';
    }
}
