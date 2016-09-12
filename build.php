<?php

$file = __DIR__ . "/wait-for-it.phar";
$phar = new Phar(__DIR__ . "/wait-for-it.phar");
$phar->startBuffering();
$defaultStub = $phar->createDefaultStub('wait-for-it.php');
$phar->buildFromDirectory(__DIR__);
$stub = "#! /usr/bin/env php \n" . $defaultStub;
$phar->setStub($stub);
$phar->stopBuffering();

passthru("chmod +x $file");