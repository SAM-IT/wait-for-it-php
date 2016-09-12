<?php

require_once 'vendor/autoload.php';

$loop = \React\EventLoop\Factory::create();

const EXIT_OK = 0;
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
function setup($loop) {
    $options = validateConfiguration();
    waitFor($options['h'], $options['t'], isset($options['runner']) ? $options['runner'] : function() { error("All good.", EXIT_OK); }, $loop);

}

function waitFor(array $targets, $timeout, \Closure $success = null, \React\EventLoop\LoopInterface $loop) {
    $tcpConnector = new \React\SocketClient\TcpConnector($loop);
    $dns = (new \React\Dns\Resolver\Factory)->create('8.8.4.4', $loop);
    $connector = new \React\SocketClient\DnsConnector($tcpConnector, $dns);
    $promises = [];
    foreach ($targets as $target) {
        list($host, $port) = explode(':', $target);
        /** @var \React\Promise\Promise $promise */
        $promises[] = $promise = $connector->create($host, $port);
        $promise->done(function() use ($host, $port) {
            echo ("Connection to $host:$port OK\n");
        }, function(\Exception $e) use ($host, $port) {
            error("Connection to $host:$port failed: {$e->getMessage()}", EXIT_RUNTIME);
        }, function() {
            var_dump(func_get_args());
        });
    }

    $timer = $loop->addTimer($timeout, function() {
        error("Timeout", EXIT_TIMEOUT);
    });

    \React\Promise\all($promises)->then(function($values) use ($success, $timer) {
        $timer->cancel();
        $success();
    });


}

setup($loop);
$loop->run();