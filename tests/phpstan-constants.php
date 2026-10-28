<?php
declare(strict_types=1);

// The constants tests/bootstrap.php defines, for static analysis only.
if (!defined('TMP')) {
    define('TMP', sys_get_temp_dir() . DIRECTORY_SEPARATOR);
}
