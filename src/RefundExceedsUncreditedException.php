<?php

/** A refund credit note would credit more than the invoice has left uncredited (InvoiceService::creditRefund()). */
class RefundExceedsUncreditedException extends LogicException {}
