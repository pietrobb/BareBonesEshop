<?php
/** Thrown by Db::get() while a restore/rollback replaces the SQLite database file. */
class DatabaseMaintenanceException extends RuntimeException {}
