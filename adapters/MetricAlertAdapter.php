<?php
/** Stable contract for publishing bounded operational metrics and alerts. */
interface MetricAlertAdapter
{
    public function publish(array $metrics, array $alerts, array $context = []): void;
}
