<?php
/**
 * Worker outbox sweep: delivers committed domain events to the configured outbound webhook
 * endpoints and to extension listeners, with per-endpoint audit rows and durable retry.
 *
 * - One endpoint never blocks the others: a misconfigured endpoint (bad URL, http without
 *   allow_insecure_http, short secret) or a throwing transport is recorded as a failed delivery
 *   for that endpoint only; the remaining endpoints and the extension listeners still run.
 * - The sweep runs before queued jobs, so it is time-boxed: no new event is claimed once
 *   $budgetSeconds have passed, and an endpoint that timed out TIMEOUT_SKIP_AFTER times in
 *   this run is skipped (left for the next run's retry) instead of stalling every event.
 */
final class OutboxDispatcher
{
    public const DEFAULT_BUDGET_SECONDS = 20;
    public const TIMEOUT_SKIP_AFTER = 2;

    /**
     * @param callable|null $transport Webhook transport for tests (see Webhook::deliver)
     * @param callable|null $clock     fn(): float, seconds; for tests
     * @return array{events:int, budget_exhausted:bool, skipped_endpoints:list<string>}
     */
    public static function run(array $cfg, bool $verbose = false, ?int $budgetSeconds = null, ?callable $transport = null, ?callable $clock = null): array
    {
        $clock ??= static fn(): float => microtime(true);
        $budget = max(1, $budgetSeconds ?? (int)($cfg['outbox_sweep_budget_seconds'] ?? self::DEFAULT_BUDGET_SECONDS));
        $started = $clock();
        $endpoints = is_array($cfg['outbound_webhooks'] ?? null) ? $cfg['outbound_webhooks'] : [];
        $timeouts = [];
        $events = 0;
        $exhausted = false;
        $pendingEvents = Outbox::pending(25);
        $invalid = Webhook::invalidEndpoints($cfg);
        if ($pendingEvents !== [] && $invalid !== []) {
            // Once per sweep with work to do, not per event: the operator fixes config (see `cli/setup.php` doctor).
            IncidentMonitor::report('webhook.endpoint_misconfigured', 'webhook', 'warning', ['count' => count($invalid)]);
        }
        foreach ($pendingEvents as $pending) {
            if ($clock() - $started >= $budget) {
                $exhausted = true;
                Logger::warning('worker.outbox_budget_exhausted', ['count' => $events]);
                break;
            }
            $event = Outbox::claim((string)$pending['id']);
            if ($event === null) continue;
            $events++;
            self::deliverEvent($event, $endpoints, $timeouts, $verbose, $transport, $clock);
        }
        $skipped = array_keys(array_filter($timeouts, static fn(int $n): bool => $n >= self::TIMEOUT_SKIP_AFTER));
        return ['events' => $events, 'budget_exhausted' => $exhausted, 'skipped_endpoints' => array_map('strval', $skipped)];
    }

    /** $timeouts: endpoint code => timeouts in this run */
    private static function deliverEvent(array $event, array $endpoints, array &$timeouts, bool $verbose, ?callable $transport, callable $clock): void
    {
        try {
            Bootstrap::initStoreContext('/', '', (string)$event['store_id'], (string)$event['channel_id']);
            $envelope = Outbox::envelope($event);
            $matched = 0;
            $failures = [];
            foreach ($endpoints as $code => $endpoint) {
                $code = (string)$code;
                if (!is_array($endpoint)) continue;
                $stores = $endpoint['stores'] ?? [];
                if (is_array($stores) && $stores !== [] && !in_array((string)$event['store_id'], $stores, true)) continue;
                if (!Webhook::endpointAccepts($endpoint, (string)$event['event_type'])) continue;
                $matched++;
                $failure = self::deliverToEndpoint($event, $envelope, $code, $endpoint, $timeouts, $transport, $clock);
                if ($failure !== null) $failures[] = $code . ':' . $failure;
            }
            // In-process listeners of enabled extensions, with the same per-endpoint retry/dedup.
            $extensionDelivery = Extensions::deliver($event, $envelope);
            $matched += $extensionDelivery['matched'];
            array_push($failures, ...$extensionDelivery['failures']);
            // Guarded by the claim: after a stale-lease takeover the other worker's outcome stands.
            $claimed = (int)$event['attempts'];
            if ($failures === []) {
                $owned = Outbox::markDelivered((string)$event['id'], $claimed);
                if ($verbose) echo "Delivered outbox event {$event['id']} to {$matched} endpoint(s)\n";
            } else {
                $owned = Outbox::markRetry((string)$event['id'], $claimed, implode(', ', $failures), $claimed);
                if ($verbose) echo "Deferred outbox event {$event['id']}: " . implode(', ', $failures) . "\n";
            }
            if (!$owned) Logger::warning('worker.outbox_claim_lost', ['event_id' => (string)$event['id']]);
        } catch (\Throwable $e) {
            Outbox::markRetry((string)$event['id'], (int)$event['attempts'], $e->getMessage(), (int)$event['attempts']);
            if ($verbose) echo "Deferred outbox event {$event['id']}: {$e->getMessage()}\n";
        }
    }

    /** @return string|null failure reason, null when delivered (now or earlier) */
    private static function deliverToEndpoint(array $event, array $envelope, string $code, array $endpoint, array &$timeouts, ?callable $transport, callable $clock): ?string
    {
        $problem = Webhook::endpointProblem($code, $endpoint);
        if ($problem !== null) {
            Logger::warning('worker.outbox_endpoint_invalid', ['endpoint' => $code]);
            // An invalid code cannot be stored as an audit row; it stays in the event's retry error.
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $code)) {
                Outbox::recordDelivery((string)$event['id'], $code, (int)$event['attempts'], false, null, 'invalid endpoint configuration: ' . $problem);
            }
            return 'invalid_config';
        }
        if (Outbox::endpointDelivered((string)$event['id'], $code)) return null;
        if (($timeouts[$code] ?? 0) >= self::TIMEOUT_SKIP_AFTER) return 'skipped_after_timeouts';

        $sent = $clock();
        try {
            $result = Webhook::deliver($endpoint, $envelope, $transport);
        } catch (\Throwable $e) {
            $result = ['delivered' => false, 'response_code' => null, 'error' => get_class($e) . ': ' . $e->getMessage(), 'timed_out' => false];
        }
        // A transport that does not report timeouts still counts a call that used the whole client timeout.
        if (!$result['delivered'] && (!empty($result['timed_out']) || $clock() - $sent >= 14.5)) {
            $timeouts[$code] = ($timeouts[$code] ?? 0) + 1;
        }
        Outbox::recordDelivery(
            (string)$event['id'],
            $code,
            (int)$event['attempts'],
            $result['delivered'],
            $result['response_code'],
            $result['error']
        );
        return $result['delivered'] ? null : (string)($result['response_code'] ?? 'network');
    }
}
