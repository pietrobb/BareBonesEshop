<?php

/** The order was priced with another VAT than placing it decides (the OSS threshold passed meanwhile): price it again. */
class VatChangedException extends RuntimeException {}
