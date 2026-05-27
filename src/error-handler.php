<?php

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (in_array($errno, [E_NOTICE, E_WARNING])) {

        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
});
