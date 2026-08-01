<?php
/** Optional full carrier lifecycle implemented by production-capable shipping adapters. */
interface ShippingLifecycleAdapter extends ShippingAdapter
{
    /** @return array<int,array{id:string,name:string,address:string}> */
    public function pickupPoints(string $country, string $postalCode = ''): array;

    /** Cancel a previously-created label without repeating the original shipment mutation. */
    public function cancelLabel(string $awb): array;
}
