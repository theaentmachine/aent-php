#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use \TheAentMachine\Aent\ServiceAent;
use \TheAentMachine\AentPhp\Event\AddEvent;

$application = new ServiceAent("PHP Apache", new AddEvent());
$application->run();
