<?php
/** An order on invoice that would take the company past its credit limit (checked inside the order transaction). */
class CreditLimitExceededException extends OrderRejectedException {}
