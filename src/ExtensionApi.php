<?php
/**
 * What an extension may register from its extension.php register() callback. Every call is
 * validated immediately and only collected here; Extensions applies the whole set after
 * register() returned, so a failing extension never leaves half of its hooks behind.
 */
final class ExtensionApi
{
    private array $providers = [];
    private array $listeners = [];
    private array $storefront = [];

    public function __construct(private string $code) {}

    public function code(): string
    {
        return $this->code;
    }

    /**
     * Add a payment/shipping/mail/tax/erp/pim/wms provider. The class is loaded from the
     * extension's src/ directory and must implement the provider type's core interface.
     */
    public function provider(string $type, string $code, string $class, array $capabilities = [], array $options = []): void
    {
        $interface = Adapters::providerInterface($type);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $code)) {
            throw new InvalidArgumentException("invalid {$type} provider code \"{$code}\"");
        }
        if (isset($this->providers[$type][$code]) || array_key_exists($code, Adapters::registry($type))) {
            throw new RuntimeException("{$type} provider \"{$code}\" already exists; extensions cannot replace core or configured providers");
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class) || !class_exists($class)) {
            throw new RuntimeException("{$type} provider class {$class} not found (expected src/{$class}.php in the extension)");
        }
        if (!is_subclass_of($class, $interface)) {
            throw new RuntimeException("{$type} provider class {$class} must implement {$interface}");
        }
        unset($options['class'], $options['scope']);
        $this->providers[$type][$code] = ['class' => $class, 'capabilities' => array_values(array_map('strval', $capabilities))]
            + $options + ['extension' => $this->code];
    }

    /**
     * Run $listener(array $envelope) for committed domain events matching $events ("order.created",
     * "order.*" or "*"). Delivery is at-least-once from the worker's outbox sweep; a throwing
     * listener is retried with backoff and never re-runs listeners that already succeeded.
     */
    public function on(string $events, string $name, callable $listener): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]{0,24}$/', $name)) {
            throw new InvalidArgumentException("invalid listener name \"{$name}\" (a-z, 0-9 and \"-\", up to 25 characters)");
        }
        $known = DomainEvents::all();
        $prefix = str_ends_with($events, '.*') ? substr($events, 0, -1) : null;
        $valid = $events === '*' || in_array($events, $known, true)
            || ($prefix !== null && array_filter($known, static fn(string $event): bool => str_starts_with($event, $prefix)) !== []);
        if (!$valid) {
            throw new InvalidArgumentException("unknown domain event \"{$events}\" (see DomainEvents)");
        }
        $endpoint = 'ext-' . $this->code . '-' . $name;
        if (isset($this->listeners[$endpoint])) throw new RuntimeException("listener \"{$name}\" registered twice");
        $this->listeners[$endpoint] = ['events' => $events, 'listener' => $listener];
    }

    /** Add data to a storefront view model under the extension's own namespace ("<code>" or "<code>.<key>"). */
    public function storefront(string $context, string $namespace, callable $provider): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,79}$/', $context)) {
            throw new InvalidArgumentException("invalid storefront context \"{$context}\"");
        }
        if (!preg_match('/^' . preg_quote($this->code, '/') . '(?:\.[a-z][a-z0-9_-]{0,39})*$/', $namespace)) {
            throw new InvalidArgumentException("storefront namespace \"{$namespace}\" must be \"{$this->code}\" or start with \"{$this->code}.\"");
        }
        $this->storefront[$context . ' ' . $namespace] = [$context, $namespace, $provider];
    }

    /** @internal Extensions::boot() */
    public function registrations(): array
    {
        return ['providers' => $this->providers, 'listeners' => $this->listeners, 'storefront' => array_values($this->storefront)];
    }
}
