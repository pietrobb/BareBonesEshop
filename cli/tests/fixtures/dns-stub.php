<?php
declare(strict_types=1);

// R62 test name server for BoundedDns (AuditRound62UxDnsRegression). php dns-stub.php <mode> <seconds>; prints its port.
//  a-only: answers A (203.0.113.7), never AAAA (a server that drops AAAA queries).
//  tc:     answers A over UDP truncated and empty, over TCP with 203.0.113.8; AAAA with no records.
[, $mode, $seconds] = $argv + [null, 'a-only', '5'];
$udp = $tcp = false;
for ($try = 0; $try < 20 && $tcp === false; $try++) {
    $udp = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
    if ($udp === false) exit(1);
    $port = (int)substr((string)strrchr((string)stream_socket_get_name($udp, false), ':'), 1);
    $tcp = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
    if ($tcp === false) fclose($udp);
}
if ($tcp === false) exit(1);
echo $port, "\n";
flush();

$reply = static function (string $query, bool $overTcp) use ($mode): ?string {
    if (strlen($query) < 17) return null;
    $id = unpack('n', $query)[1];
    $type = unpack('n', substr($query, -4, 2))[1];
    $question = substr($query, 12);
    $records = [];
    $flags = 0x8180;
    if ($type === 1 && $mode === 'a-only') $records[] = '203.0.113.7';
    elseif ($type === 1 && $mode === 'tc' && $overTcp) $records[] = '203.0.113.8';
    elseif ($type === 1 && $mode === 'tc') $flags |= 0x0200;
    elseif ($type === 28 && $mode === 'a-only') return null;
    $packet = pack('nnnnnn', $id, $flags, 1, count($records), 0, 0) . $question;
    foreach ($records as $ip) $packet .= pack('nnnNn', 0xC00C, 1, 1, 60, 4) . inet_pton($ip);
    return $packet;
};

$until = microtime(true) + (float)$seconds;
$clients = [];
while (microtime(true) < $until) {
    $read = array_merge([$udp, $tcp], $clients);
    $write = $except = null;
    if (@stream_select($read, $write, $except, 0, 200000) < 1) continue;
    foreach ($read as $socket) {
        if ($socket === $udp) {
            $query = stream_socket_recvfrom($udp, 512, 0, $peer);
            $answer = $reply((string)$query, false);
            if ($answer !== null) stream_socket_sendto($udp, $answer, 0, $peer);
        } elseif ($socket === $tcp) {
            $client = @stream_socket_accept($tcp, 1);
            if ($client !== false) $clients[] = $client;
        } else {
            $length = fread($socket, 2);
            $query = strlen((string)$length) === 2 ? fread($socket, unpack('n', $length)[1]) : '';
            $answer = $reply((string)$query, true);
            if ($answer !== null) fwrite($socket, pack('n', strlen($answer)) . $answer);
            fclose($socket);
            $clients = array_values(array_filter($clients, static fn($c): bool => $c !== $socket));
        }
    }
}
