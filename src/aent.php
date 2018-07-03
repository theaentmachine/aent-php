#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use TheAentMachine\AentApplication;
use TheAentMachine\AentPhp\Command\AddEventCommand;

$application = new AentApplication();

$application->add(new AddEventCommand());

$application->run();
