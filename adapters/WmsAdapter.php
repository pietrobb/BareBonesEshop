<?php
/** Stable WMS contract for authoritative stock snapshots. */
interface WmsAdapter
{
    /** @return iterable<array{sku:string,qty:int}> */
    public function pullStock(): iterable;
}
