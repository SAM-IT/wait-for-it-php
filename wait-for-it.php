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

function getHosts()
{
// Load hosts file.
    $hosts = [];
    if (file_exists('/etc/hosts')) {
        foreach (file('/etc/hosts') as $record) {
            if (preg_match_all('/(.+?)(?:\s+|$)/', $record, $matches)) {
                $ip = array_shift($matches[1]);
                if ($ip === '#') {
                    continue;
                }
                foreach ($matches[1] as $name) {
                    $hosts[$name] = $ip;
                }
            }
        }
    }
    return $hosts;
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

    $hosts = getHosts();

    foreach($options['h'] as &$target) {
        if (!preg_match('/^(?<host>.*):(?<port>\d+)$/', $target, $matches)) {
            error("Invalid host specification: $target", EXIT_MISCONFIGURATION);
        } elseif (intval($matches[1]) > 65535) {
            error("Invalid port: {$matches['port']}", EXIT_MISCONFIGURATION);
        } elseif (isset($hosts[$matches['host']])) {
            $target = "{$hosts[$matches['host']]}:{$matches['port']}";
        } else {
            $target = gethostbyname($matches['host']) . ":{$matches['port']}";
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
    $connector = new \React\SocketClient\TcpConnector($loop);
    $promises = [];

    foreach ($targets as $target) {
        list($ip, $port) = explode(':', $target);
        $targetResult = new \React\Promise\Deferred();
        $promises[] = $promise = $targetResult->promise();
        /**
         * @return \React\Promise\PromiseInterface
         */
        $createPromise = function(\Closure $otherwise) use ($ip, $port, $connector, $targetResult) {
            return $connector->create($ip, $port)
                ->then(function() use ($targetResult) {
                    $targetResult->resolve();
                }, $otherwise);
        };

        $retry = function() use ($loop, $createPromise, &$retry) {
            // Retry in 1 sec.
            $loop->addTimer(1, function() use ($createPromise, $retry) {
                echo '.';
                /** @var \React\Promise\Promise $p */
                $createPromise($retry);
            });
        };


        $createPromise($retry);

        $promise->then(function() use ($ip, $port) { echo "Connected to: $ip:$port\n"; });


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
echo "Starting loop\n";
$loop->run();
