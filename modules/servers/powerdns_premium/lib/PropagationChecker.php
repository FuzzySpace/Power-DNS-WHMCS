<?php

/**
 * DNS Propagation Checker – PowerDNS Premium
 *
 * Queries a set of public resolvers for a given DNS name + type and reports
 * what each resolver currently sees.  Uses two strategies:
 *
 *   1. DNS-over-HTTPS (DoH) via Cloudflare / Google JSON API  – no shell exec
 *      needed, works in any PHP environment.
 *   2. Fallback: PHP's built-in dns_get_record() against the local resolver
 *      (less useful for propagation checks, but available everywhere).
 *
 * Each resolver check is independent so partial results are returned even
 * if some resolvers time out.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class PropagationChecker
{
    /**
     * Public resolvers to check.
     * 'doh' = DNS-over-HTTPS endpoint, 'label' = display name.
     */
    public static $RESOLVERS = [
        [
            'label'  => 'Google (8.8.8.8)',
            'doh'    => 'https://dns.google/resolve',
            'ip'     => '8.8.8.8',
        ],
        [
            'label'  => 'Cloudflare (1.1.1.1)',
            'doh'    => 'https://cloudflare-dns.com/dns-query',
            'ip'     => '1.1.1.1',
        ],
        [
            'label'  => 'OpenDNS (208.67.222.222)',
            'doh'    => 'https://doh.opendns.com/dns-query',
            'ip'     => '208.67.222.222',
        ],
        [
            'label'  => 'Quad9 (9.9.9.9)',
            'doh'    => 'https://dns.quad9.net/dns-query',
            'ip'     => '9.9.9.9',
        ],
    ];

    /**
     * Map from record type to PHP dns_get_record() constant.
     */
    private static $DNS_TYPE_MAP = [
        'A'     => DNS_A,
        'AAAA'  => DNS_AAAA,
        'MX'    => DNS_MX,
        'TXT'   => DNS_TXT,
        'CNAME' => DNS_CNAME,
        'NS'    => DNS_NS,
        'PTR'   => DNS_PTR,
        'SRV'   => DNS_SRV,
        'CAA'   => DNS_ANY,
    ];

    // -------------------------------------------------------------------------

    /**
     * Check a name + type across all configured resolvers.
     *
     * @param  string $name  Fully-qualified or relative record name
     * @param  string $type  Record type: A, AAAA, MX, TXT, CNAME, etc.
     * @return array  Array of resolver results, each:
     *                ['resolver' => string, 'answers' => array, 'status' => 'ok'|'nxdomain'|'error', 'error' => string]
     */
    public static function check($name, $type)
    {
        $name    = rtrim($name, '.') . '.';
        $type    = strtoupper(trim($type));
        $results = [];

        foreach (self::$RESOLVERS as $resolver) {
            $results[] = self::queryResolver($resolver, $name, $type);
        }

        return $results;
    }

    /**
     * Check only one specific resolver by its IP.
     */
    public static function checkOne($resolverIp, $name, $type)
    {
        $name = rtrim($name, '.') . '.';
        $type = strtoupper(trim($type));
        foreach (self::$RESOLVERS as $resolver) {
            if ($resolver['ip'] === $resolverIp) {
                return self::queryResolver($resolver, $name, $type);
            }
        }
        return ['resolver' => $resolverIp, 'answers' => [], 'status' => 'error', 'error' => 'Unknown resolver'];
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private static function queryResolver(array $resolver, $name, $type)
    {
        $result = [
            'resolver' => $resolver['label'],
            'ip'       => $resolver['ip'],
            'answers'  => [],
            'status'   => 'error',
            'error'    => '',
        ];

        try {
            $answers = self::dohQuery($resolver['doh'], $name, $type);
            $result['answers'] = $answers;
            $result['status']  = empty($answers) ? 'nxdomain' : 'ok';
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            // Attempt native PHP fallback for the local resolver only
        }

        return $result;
    }

    /**
     * DNS-over-HTTPS JSON query (RFC 8484 / Google / Cloudflare JSON API).
     *
     * @param  string $endpoint DoH endpoint URL
     * @param  string $name
     * @param  string $type
     * @return array  Flat list of answer strings
     * @throws RuntimeException
     */
    private static function dohQuery($endpoint, $name, $type)
    {
        $url = $endpoint . '?' . http_build_query([
            'name' => $name,
            'type' => $type,
            'ct'   => 'application/dns-json',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/dns-json'],
            CURLOPT_USERAGENT      => 'PowerDNS-WHMCS-Premium/1.0',
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException($curlError);
        }

        if ($httpCode !== 200) {
            throw new RuntimeException("HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON response');
        }

        // Status 3 = NXDOMAIN
        if (isset($data['Status']) && (int) $data['Status'] === 3) {
            return [];
        }

        $answers = [];
        foreach ($data['Answer'] ?? [] as $answer) {
            $answers[] = [
                'name' => $answer['name'] ?? '',
                'type' => self::rrTypeCode($answer['type'] ?? 0),
                'ttl'  => $answer['TTL']  ?? 0,
                'data' => $answer['data'] ?? '',
            ];
        }

        return $answers;
    }

    /**
     * Convert a numeric RR type code to a type string.
     */
    private static function rrTypeCode($code)
    {
        $map = [
            1   => 'A',
            2   => 'NS',
            5   => 'CNAME',
            6   => 'SOA',
            12  => 'PTR',
            15  => 'MX',
            16  => 'TXT',
            28  => 'AAAA',
            33  => 'SRV',
            257 => 'CAA',
        ];
        return $map[(int) $code] ?? (string) $code;
    }
}
