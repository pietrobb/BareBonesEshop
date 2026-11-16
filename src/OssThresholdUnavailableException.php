<?php

/** R38 (low): the OSS threshold could not be counted, so the VAT of a distance sale is unknown (OssThreshold::exceeded()). */
class OssThresholdUnavailableException extends RuntimeException {}
