#!/usr/bin/env php
<?php

const EXIT_MISCONFIGURATION = 2;
const EXIT_RUNTIME = 3;
const EXIT_TIMEOUT = 4;
/**
 * Output usage / help.
 */
function help() {
    fwrite(STDERR, "Usage information:\n");
    fwrite(STDERR,  "-h host:port [-h host:port] [-t 10]\n");
}

function error($message, $code)
{
    fwrite(STDERR, $message . "\n");

    if ($code == EXIT_MISCONFIGURATION) {
        help();
    }
    exit($code);
}

function validateConfiguration() {
    // Parse arguments.
    $defaults = [
        'h' => [],
        't' => [
            10
        ]
    ];
    $options = array_merge_recursive($defaults, getopt('t:h:', [
        'help'
    ]));

    if (isset($options['help'])) {
        help();
        exit(0);
    }

    if (empty($options['h'])) {
        error("At least one host must be specified.", EXIT_MISCONFIGURATION);
    }

    foreach($options['h'] as $target) {
        if (!preg_match('/^.*:(\d+)$/', $target, $matches)) {
            error("Invalid host specification: $target", EXIT_MISCONFIGURATION);
        } elseif (intval($matches[1]) > 65535) {
            error("Invalid port: {$matches[1]}", EXIT_MISCONFIGURATION);
        }
    }

    $pos = array_search('--', $_SERVER['argv']);
    if ($pos !== false) {
        $args = array_slice($_SERVER['argv'], $pos + 2);
        $cmd = $_SERVER['argv'][$pos + 1];
        $options['runner'] = function() use ($args, $cmd) {
            echo "Running: $cmd [" . implode(' ', $args) . "]\n";
            pcntl_exec($cmd, $args, $_ENV);
        };
    }

    $options['t'] = intval(array_pop($options['t']));
    return $options;
}
function run() {
    $options = validateConfiguration();
    waitFor($options['h'], $options['t'], isset($options['runner']) ? $options['runner'] : null);

}

function waitFor(array $targets, $timeout, \Closure $success = null) {
    foreach ($targets as $target) {
        list($host, $port) = explode(':', $target);

        if (!preg_match('/(\d+\.){3}\d+/', $host)) {
            echo "Resolving $host.. ";
            $host = gethostbyname($host);
            echo "--> $host\n";
        }
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $sockets[intval($sock)] = $sock;
        $definitions[intval($sock)] = [$host, $port];
        if ($sock !== false) {
            socket_set_nonblock($sock);
            socket_connect($sock, $host, $port);
            socket_clear_error($sock);
        } else {
            error("Failed to create socket for: $host : $port", EXIT_RUNTIME);
        }
    }
    echo "All sockets set up. Timeout: $timeout.\n";
    $startTime = microtime(true);
    while (!empty($sockets) && microtime(true) - $startTime < $timeout) {
        $read = $sockets;
        $write = $sockets;
        $except = $sockets;

        socket_select($read, $write, $except, ceil($timeout - (microtime(true) - $startTime)));
        foreach (array_unique(array_merge($read, $write, $except)) as $sock) {
            $k = intval($sock);
            $status = socket_last_error($sock);
            socket_clear_error($sock);
            if ($status === 0) {
                echo "Connected to: {$definitions[$k][0]}:{$definitions[$k][1]} -- $status >> " . socket_strerror($status) . "\n";
                unset($sockets[intval($sock)]);

            } elseif (in_array($status, [111])) {
                die("Connection to {$definitions[$k][0]}:{$definitions[$k][1]} failed: " . socket_strerror($status) . "\n");
            } else {
                echo "{$definitions[$k][0]}:{$definitions[$k][1]} -- $status >> " . socket_strerror($status) . "\n";
            }



        }
        sleep(1);
    }
    if (!empty($sockets)) {
        error("Timeout occured.", EXIT_TIMEOUT);
    } elseif (isset($success)) {
        $success();
    } else {
        error("All targets are up.", 0);
    }

}

run();