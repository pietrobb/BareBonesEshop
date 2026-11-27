<?php
declare(strict_types=1);

/**
 * R61 (review round 26, low 10): the A and AAAA addresses of a host, answered within a deadline. dns_get_record() and
 * gethostbynamel() wait as long as the system resolver does: with a name server that did not answer, every store save
 * probing a domain took 12 s although the probe had 8. This asks the name servers of /etc/resolv.conf over UDP itself and
 * gives up at the deadline. Without a resolv.conf (Windows) the system resolver is used, unbounded as before.
 */
final class BoundedDns
{
    private const TYPE_A = 1;
    private const TYPE_AAAA = 28;

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
        if ($servers === []) return self::system($host);
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
        $address = str_contains($server, ':') ? "udp://[{$server}]:{$port}" : "udp://{$server}:{$port}";
        $socket = @stream_socket_client($address, $errno, $error, 1);
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
            while ($pending !== []) {
                $wait = $until - microtime(true);
                if ($wait <= 0) return null;
                $read = [$socket];
                $write = $except = null;
                if (@stream_select($read, $write, $except, (int)$wait, (int)(fmod($wait, 1) * 1e6)) < 1) continue;
                $packet = @fread($socket, 4096);
                if (!is_string($packet) || strlen($packet) < 12) continue;
                $id = unpack('n', $packet)[1];
                if (!isset($pending[$id])) continue;
                $parsed = self::answers($packet, $pending[$id]);
                if ($parsed === null) continue;
                unset($pending[$id]);
                array_push($addresses, ...$parsed);
            }
            return array_values(array_unique($addresses));
        } finally {
            fclose($socket);
        }
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

    /** @return list<string> */
    private static function resolvConf(): array
    {
        $servers = [];
        foreach (@file('/etc/resolv.conf', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*nameserver\s+([0-9a-fA-F.:]+)(?:%\S+)?\s*$/', $line, $m) === 1 && filter_var($m[1], FILTER_VALIDATE_IP)) $servers[] = $m[1];
        }
        return array_slice($servers, 0, 3);
    }

    /** @return list<string> the /etc/hosts addresses of $host */
    private static function hostsFile(string $host): array
    {
        $ips = [];
        foreach (@file('/etc/hosts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim((string)preg_replace('/#.*/', '', $line))) ?: [];
            if (count($fields) > 1 && filter_var($fields[0], FILTER_VALIDATE_IP) && in_array($host, array_map('strtolower', array_slice($fields, 1)), true)) $ips[] = $fields[0];
        }
        return array_values(array_unique($ips));
    }

    /** @return list<string> */
    private static function system(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) $ips[] = $ip;
        }
        return array_values(array_unique($ips ?: (@gethostbynamel($host) ?: [])));
    }
}
