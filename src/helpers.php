<?php

use Imbrish\LetsEncrypt\Command;

// send email notification

function sendNotification($subject, $message) {
    global $climate, $config;

    if (! $climate->arguments->defined('notify')) {
        return;
    }

    $address = $climate->arguments->get('notify') ?: $config['notify'];

    $result = @mail($address, $subject, $message);

    if (! $result) {
    	$climate->shout('Failed to send email notification: ' . error_get_last()['message']);
    }
}

// report processing error and exit

function reportErrorAndExit($message) {
    global $climate;

    $climate->error($message);

    sendNotification($message, Command::$last . PHP_EOL . Command::$output);

    exit(EX_PROCESSING_ERROR);
}

// recursively remove directory

function removeDirectory($dir) {
    if (! is_dir($dir)) {
        return;
    }

    foreach (array_diff(scandir($dir), ['.','..']) as $file) {
        if (is_dir($path = $dir . '/' . $file)) {
            removeDirectory($path);
        }
        else {
            unlink($path);
        }
    }

    rmdir($dir);
}
