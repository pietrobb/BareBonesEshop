<?php
/** Extensible post-transition fulfillment job dispatcher. */
class FulfillmentDispatcher {
    /** @var array<string,array<int,callable>> */
    private static array $handlers = [];

    public static function register(string $event, callable $handler): void {
        self::$handlers[$event][] = $handler;
    }

    /** @return array<int,array{0:string,1:array,2:int}> */
    public static function dispatch(string $event, array $order): array {
        $jobs = self::builtInJobs($event, $order);
        foreach (self::$handlers[$event] ?? [] as $handler) {
            foreach ((array)$handler($order, $event) as $job) $jobs[] = $job;
        }
        return $jobs;
    }

    public static function queue(string $event, array $order): void {
        foreach (self::dispatch($event, $order) as [$type, $payload, $priority]) {
            JobQueue::push($type, $payload, $priority);
        }
    }

    private static function builtInJobs(string $event, array $order): array {
        $orderId = (int)($order['id'] ?? 0);
        if ($orderId <= 0) return [];
        return match ($event) {
            'paid' => [
                ['digital_fulfill', ['order_id' => $orderId], 2],
                ['merchant_notify', ['order_id' => $orderId, 'event' => 'paid'], 6],
            ],
            'refunded', 'disputed', 'cancelled' => [
                ['digital_revoke', ['order_id' => $orderId, 'reason' => $event], 1],
            ],
            default => [],
        };
    }
}
