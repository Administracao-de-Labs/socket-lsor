<?php

register_shutdown_function(function () {
    $error = error_get_last();

    if ($error) {
        throw new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
    }
});