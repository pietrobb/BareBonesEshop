<?php
declare(strict_types=1);

/**
 * R61 (review round 26, low 10): the A and AAAA addresses of a host, answered within a deadline. dns_get_record() and
 * gethostbynamel() wait as long as the system resolver does: with a name server that did not answer, every store save
 * probing a domain took 12 s although the probe had 8. This asks the name servers of /etc/resolv.conf over UDP itself and
 * gives up at the deadline. Without a resolv.conf (Windows) the system resolver is used, unbounded as before.
 * R62 (review round 27, finding 7): a name server that answers A but drops AAAA gives the A addresses (was: no answer
 * until the deadline, the domain untested on every save); a truncated (TC) answer is asked again over TCP; an
 * unreadable resolv.conf (open_basedir) or disabled socket functions (disable_functions) give no answer (untested)
 * instead of a fatal error or the unbounded system resolver.
 */
final class BoundedDns
{
    private const TYPE_A = 1;
    private const TYPE_AAAA = 28;
    /** How long the other type is waited for once one type has answered with addresses. */
    private const OTHER_TYPE_GRACE = 0.5;
    private const SOCKET_FUNCTIONS = ['stream_socket_client', 'stream_select', 'stream_set_blocking', 'stream_set_timeout'];

    /** @var list<string>|null */
    private static ?array $nameservers = null;

    /** Test hook: the name servers to ask, "ip" or "ip:port" (null = /etc/resolv.conf's). */
    public static function setNameserversForTesting(?array $nameservers): void
    {
        self::$nameservers = $nameservers;
    }

    /**
     * @return list<string>|null  the addresses ([] when the name has none), null when no name server answered by $deadline
     *                           (microtime(true))
     */
    public static function resolve(string $host, float $deadline): ?array
    {
        $host = strtolower(rtrim($host, '.'));
        $hosts = self::hostsFile($host);
        if ($hosts !== []) return $hosts;
        $servers = self::$nameservers ?? self::resolvConf();
        if ($servers === null) return PHP_OS_FAMILY === 'Windows' ? self::system($host) : null;
        foreach (self::SOCKET_FUNCTIONS as $function) {
            if (!function_exists($function)) return null;
        }
        foreach ($servers as $index => $server) {
            // Each server gets its share of the time left, the last one all of it.
            $left = $deadline - microtime(true);
            if ($left <= 0) return null;
            $until = microtime(true) + $left / (count($servers) - $index);
            $answer = self::ask($server, $host, $until);
            if ($answer !== null) return $answer;
        }
        return null;
    }

    /** @return list<string>|null */
    private static function ask(string $server, string $host, float $until): ?array
    {
        $port = 53;
        if (!filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && preg_match('/^(.+):(\d+)$/', $server, $m) === 1) [$server, $port] = [$m[1], (int)$m[2]]; // test hook "ip:port"
        $target = str_contains($server, ':') ? "[{$server}]:{$port}" : "{$server}:{$port}";
        $socket = @stream_socket_client("udp://{$target}", $errno, $error, 1);
        if (!is_resource($socket)) return null;
        try {
            stream_set_blocking($socket, false);
            $pending = [];
            foreach ([self::TYPE_A, self::TYPE_AAAA] as $type) {
                $id = random_int(0, 0xFFFF);
                $pending[$id] = $type;
                if (@fwrite($socket, self::query($id, $host, $type)) === false) return null;
            }
            $addresses = [];
            $answered = 0;
            while ($pending !== []) {
                $wait = $until - microtime(true);
                if ($wait <= 0) break;
                $read = [$socket];
                $write = $except = null;
                if (@stream_select($read, $write, $except, (int)$wait, (int)(fmod($wait, 1) * 1e6)) < 1) continue;
                $packet = @fread($socket, 4096);
                if (!is_string($packet) || strlen($packet) < 12) continue;
                $id = unpack('n', $packet)[1];
                if (!isset($pending[$id])) continue;
                $type = $pending[$id];
                $parsed = self::answers($packet, $type);
                if ($parsed === null) continue;
                unset($pending[$id]);
                if (self::truncated($packet)) {
                    // The records did not fit in UDP: the whole answer is asked over TCP (its part is kept if TCP fails).
                    $whole = self::askTcp($target, $host, $type, $until);
                    if ($whole === null && $parsed === []) continue;
                    $parsed = $whole ?? $parsed;
                }
                $answered++;
                array_push($addresses, ...$parsed);
                if ($addresses !== []) $until = min($until, microtime(true) + self::OTHER_TYPE_GRACE);
            }
            // Both types answered, or one with addresses: a server dropping the other type does not hide them.
            return $answered === 2 || $addresses !== [] ? array_values(array_unique($addresses)) : null;
        } finally {
            fclose($socket);
        }
    }

    private static function truncated(string $packet): bool
    {
        return (unpack('n', substr($packet, 2, 2))[1] & 0x0200) !== 0;
    }

    /** @return list<string>|null the addresses of $type asked over TCP (RFC 1035 4.2.2: length-prefixed), null on no answer */
    private static function askTcp(string $target, string $host, int $type, float $until): ?array
    {
        $wait = $until - microtime(true);
        if ($wait <= 0) return null;
        $socket = @stream_socket_client("tcp://{$target}", $errno, $error, $wait);
        if (!is_resource($socket)) return null;
        try {
            $id = random_int(0, 0xFFFF);
            $query = self::query($id, $host, $type);
            if (@fwrite($socket, pack('n', strlen($query)) . $query) === false) return null;
            $length = self::readExactly($socket, 2, $until);
            if ($length === null) return null;
            $packet = self::readExactly($socket, unpack('n', $length)[1], $until);
            if ($packet === null || strlen($packet) < 12 || unpack('n', $packet)[1] !== $id) return null;
            return self::answers($packet, $type);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private static function readExactly($socket, int $bytes, float $until): ?string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $wait = $until - microtime(true);
            if ($wait <= 0) return null;
            stream_set_timeout($socket, (int)$wait, (int)(fmod($wait, 1) * 1e6));
            $chunk = @fread($socket, $bytes - strlen($data));
            if (!is_string($chunk) || ($chunk === '' && feof($socket))) return null;
            $data .= $chunk;
        }
        return $data;
    }

    private static function query(int $id, string $host, int $type): string
    {
        $name = '';
        foreach (explode('.', $host) as $label) $name .= chr(strlen($label)) . $label;
        return pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0) . $name . "\0" . pack('nn', $type, 1);
    }

    /** @return list<string>|null  the addresses of $type in a response; null when it is no usable response */
    private static function answers(string $packet, int $type): ?array
    {
        $header = unpack('nid/nflags/nqd/nan', substr($packet, 0, 8));
        if (!is_array($header) || ($header['flags'] & 0x8000) === 0) return null;
        $rcode = $header['flags'] & 0x000F;
        if ($rcode === 3) return []; // NXDOMAIN
        if ($rcode !== 0) return null; // SERVFAIL, REFUSED: not an answer
        $offset = 12;
        for ($i = 0; $i < $header['qd']; $i++) {
            $offset = self::skipName($packet, $offset);
            if ($offset === null) return null;
            $offset += 4;
        }
        $addresses = [];
        for ($i = 0; $i < $header['an']; $i++) {
            $offset = self::skipName($packet, $offset);
            if ($offset === null || strlen($packet) < $offset + 10) return null;
            $record = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
            $offset += 10;
            $data = substr($packet, $offset, $record['length']);
            $offset += $record['length'];
            if ($record['type'] !== $type || strlen($data) !== ($type === self::TYPE_A ? 4 : 16)) continue; // CNAME and others
            $ip = @inet_ntop($data);
            if (is_string($ip)) $addresses[] = $ip;
        }
        return $addresses;
    }

    private static function skipName(string $packet, int $offset): ?int
    {
        $length = strlen($packet);
        while ($offset < $length) {
            $byte = ord($packet[$offset]);
            if ($byte === 0) return $offset + 1;
            if (($byte & 0xC0) === 0xC0) return $offset + 2; // compression pointer ends the name
            $offset += $byte + 1;
        }
        return null;
    }

    /** @return list<string>|null the name servers; null when there is no readable resolv.conf (Windows, open_basedir) */
    private static function resolvConf(): ?array
    {
        $lines = function_exists('file') ? @file('/etc/resolv.conf', FILE_IGNORE_NEW_LINES) : false;
        if ($lines === false) return null;
        $servers = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*nameserver\s+([0-9a-fA-F.:]+)(?:%\S+)?\s*$/', $line, $m) === 1 && filter_var($m[1], FILTER_VALIDATE_IP)) $servers[] = $m[1];
        }
        // No nameserver line: the resolver asks the local host (resolv.conf(5)).
        return array_slice($servers, 0, 3) ?: ['127.0.0.1'];
    }

    /** @return list<string> the /etc/hosts addresses of $host */
    private static function hostsFile(string $host): array
    {
        $ips = [];
        foreach ((function_exists('file') ? @file('/etc/hosts', FILE_IGNORE_NEW_LINES) : false) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim((string)preg_replace('/#.*/', '', $line))) ?: [];
            if (count($fields) > 1 && filter_var($fields[0], FILTER_VALIDATE_IP) && in_array($host, array_map('strtolower', array_slice($fields, 1)), true)) $ips[] = $fields[0];
        }
        return array_values(array_unique($ips));
    }

    /** @return list<string>|null null when the system lookups are disabled (disable_functions) */
    private static function system(string $host): ?array
    {
        if (!function_exists('dns_get_record') || !function_exists('gethostbynamel')) return null;
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) $ips[] = $ip;
        }
        return array_values(array_unique($ips ?: (@gethostbynamel($host) ?: [])));
    }
}
