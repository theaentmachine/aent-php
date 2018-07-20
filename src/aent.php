#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use TheAentMachine\AentApplication;
use TheAentMachine\Command\CannotHandleAddEventCommand;
use TheAentMachine\AentPhp\Command\StartEventCommand;

$application = new AentApplication();

$application->add(new CannotHandleAddEventCommand());
$application->add(new StartEventCommand());

$application->run();
