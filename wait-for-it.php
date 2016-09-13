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
    fwrite(STDERR,  "-h host:port Wait for host:port\n");
    fwrite(STDERR,  "-f path Wait for file existence\n");
    fwrite(STDERR,  "-t integer[10] Timeout\n");
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
        'f' => [],
        't' => [
            10
        ]
    ];
    $options = array_merge_recursive($defaults, getopt('t:h:f:', [
        'help'
    ]));

    if (isset($options['help'])) {
        help();
        exit(0);
    }

    if (empty($options['h']) && empty($options['f'])) {
        error("At least one host or path must be specified.", EXIT_MISCONFIGURATION);
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
    $promise = \React\Promise\all([
        waitForServers($options['h'], $loop),
        waitForFiles($options['f'], $loop)
    ]);

    $timer = $loop->addTimer($options['t'], function() {
        error("Timeout", EXIT_TIMEOUT);
    });


    $promise->then(function() use ($options, $timer) {
        $timer->cancel();
        if (isset($options['runner'])) {
            $options['runner']();
        } else {
            error("All good.", EXIT_OK);
        }
    });

}

function waitForServers(array $targets, \React\EventLoop\LoopInterface $loop) {
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

    return \React\Promise\all($promises);
}

function waitForFiles(array $targets, \React\EventLoop\LoopInterface $loop) {


    $promises = [];
    foreach($targets as $target) {
        $deferred = new React\Promise\Deferred();
        $promises[] =  $deferred->promise();
        $loop->addPeriodicTimer(1, function() use ($deferred, $target) {
            if (file_exists($target)) {
                $deferred->resolve(true);
            }
        });
    }

    return \React\Promise\all($promises);
}

setup($loop);
$loop->run();