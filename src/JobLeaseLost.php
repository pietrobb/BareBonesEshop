<?php

/** A running job's lease expired and another worker took the job over; this run must stop. */
class JobLeaseLost extends RuntimeException {}
