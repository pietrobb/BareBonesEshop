<?php
/** A coupon limit was used up while the order was being placed (a concurrent order won the last use): a refusal, not an incident. */
class CouponRejectedException extends RuntimeException {}
