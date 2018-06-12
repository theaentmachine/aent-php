<?php

namespace TheAentMachine\AentPhp\Command;

use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use TheAentMachine\AentPhp\EventEnum;
use TheAentMachine\EventCommand;
use TheAentMachine\Hercule;
use TheAentMachine\Hermes;
use TheAentMachine\Pheromone;

class AddEventCommand extends EventCommand
{
    protected function getEventName(): string
    {
        return EventEnum::ADD;
    }

    protected function executeEvent(?string $payload): void
    {
        $helper = $this->getHelper('question');

        $question = new Question('Please enter the name of the service [app]: ', 'app');
        $serviceName = $helper->ask($this->input, $this->output, $question);

        $question = new ChoiceQuestion(
            'Select your PHP version :',
            array('7.2', '7.1'),
            0
        );
        $phpVersion = $helper->ask($this->input, $this->output, $question);

        $question = new ChoiceQuestion(
            'Select your variant :',
            array('Apache', 'PHP-FPM', 'CLI'),
            0
        );
        $variant = $helper->ask($this->input, $this->output, $question);

        $variantsMap = [
            'Apache' => 'apache',
            'PHP-FPM' => 'fpm',
            'CLI' => 'cli',
        ];
        $variant = $variantsMap[$variant];

        $question = new ChoiceQuestion(
            'Do you want to install NodeJS :',
            array('No', 'Node 10.x', 'Node 8.x', 'Node 6.x'),
            0
        );
        $node = $helper->ask($this->input, $this->output, $question);

        $nodesMap = [
            'No' => '',
            'Node 10.x' => '-node10',
            'Node 8.x' => '-node8',
            'Node 6.x' => '-node6',
        ];
        $node = $nodesMap[$node];

        $imageName = "thecodingmachine/php:$phpVersion-v1-$variant$node";

        do {
            $this->output->writeln('Now, we need to find the root of your web application. This is typically the directory that contains your composer.json file.');
            $question = new Question('What is your PHP application root directory? (relative to the project root directory): ', '');
            $appDirectory = $helper->ask($this->input, $this->output, $question);

            $appDirectory = ltrim($appDirectory, '/');
            $rootDir = Pheromone::getContainerProjectDirectory();

            $fullDir = $rootDir.'/'.$appDirectory;
            if (!is_dir($fullDir)) {
                $this->output->writeln('<error>Could not find directory '.$fullDir.'</error>');
                $appDirectory = null;
            }
        } while ($appDirectory === null);

        $webDirectory = null;
        if ($variant === 'apache') {
            $question = new ChoiceQuestion(
                'Do you have a public web folder that is not the root of your application? [Yes] ',
                array('Yes', 'No'),
                0
            );
            $answer = $helper->ask($this->input, $this->output, $question);
            if ($answer === 'Yes') {
                do {
                    $question = new Question('What is your PHP application web directory? (relative to the PHP project directory): ', '');
                    $webDirectory = $helper->ask($this->input, $this->output, $question);

                    $webDirectory = ltrim($webDirectory, '/');
                    $rootDir = Pheromone::getContainerProjectDirectory();

                    $fullDir = $rootDir.'/'.$appDirectory.'/'.$webDirectory;
                    if (!is_dir($fullDir)) {
                        $this->output->writeln('<error>Could not find directory '.$fullDir.'</error>');
                        $webDirectory = null;
                    }
                } while ($webDirectory === null);
            }
        }

        // TODO: configure extensions.
        // TODO: configure file uploads.
        // TODO: configure PHP
        // TODO: propose to run composer install on startup?
        // TODO: does it depends on another service?

        $service = [
            'serviceName' => $serviceName,
            'service' => [
                'image' => $imageName,
                //'dependsOn' => ["foo"],
                //"ports" => [["source" => 80, "target" => 8080]],
                //"labels" => [["key" => "foo", "value" => "bar"]],
                'volumes' => [
                    [
                        'type' => 'bind',
                        'source' => './' .$appDirectory,
                        'target' => '/var/www/html',
                        //'readOnly' => true
                    ]
                ]
            ]
        ];

        if ($variant === 'apache') {
            $service['service']['internalPorts'] = [80];
        }
        $environment = [];
        if ($webDirectory !== null) {
            $environment[] = [
                'key' => 'APACHE_DOCUMENT_ROOT',
                'value' => $webDirectory
            ];
        }

        $service['service']['environments'] = $environment;

        Hercule::setHandledEvents(EventEnum::getHandledEvents());

        Hermes::dispatchJson(EventEnum::NEW_DOCKER_SERVICE_INFO, $service);
    }
}
