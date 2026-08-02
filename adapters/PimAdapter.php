<?php
/** Stable PIM contract for incremental catalog exchange. */
interface PimAdapter
{
    public function pullProducts(?\DateTimeInterface $since = null): iterable;

    public function pushProduct(array $product): void;
}
