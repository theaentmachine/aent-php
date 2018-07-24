<?php

namespace TheAentMachine\AentPhp\Command;

use Symfony\Component\Console\Question\Question;
use TheAentMachine\CommonEvents;
use TheAentMachine\Command\EventCommand;
use TheAentMachine\Aenthill\Pheromone;
use TheAentMachine\Registry\RegistryClient;
use TheAentMachine\Service\Service;

class StartEventCommand extends EventCommand
{
    protected function getEventName(): string
    {
        return 'START';
    }

    protected function executeEvent(?string $payload): ?string
    {
        $this->getAentHelper()->title('Adding a new PHP service');
        
        $commentEvents = new CommonEvents($this->getAentHelper(), $this->output);

        /************************ Environments **********************/
        $environments = $this->getAentHelper()->askForEnvironments();

        $service = new Service();

        /************************ Service name **********************/
        $serviceName = $this->getAentHelper()->askForServiceName('app', 'Your PHP application');
        $this->output->writeln("<info>You are about to create a '$serviceName' PHP container</info>");
        $service->setServiceName($serviceName);

        /************************ PHP Version **********************/
        [
            'phpVersions' => $phpVersions,
            'variants' => $variants,
            'nodeVersions' => $nodeVersions,
        ] = $this->getAvailableVersionParts();

        $phpVersion = $this->getAentHelper()
            ->choiceQuestion(
                'PHP version',
                $phpVersions
            )
            ->setDefault('0')
            ->ask();
        $this->output->writeln("<info>You are about to install PHP $phpVersion</info>");

        $variant = $this->getAentHelper()
            ->choiceQuestion(
                'Variant',
                $variants
            )
            ->setDefault('0')
            ->ask();
        $this->output->writeln("<info>You selected the $variant variant</info>");

        $node = $this->getAentHelper()
            ->question('Do you want to install NodeJS?')
            ->yesNoQuestion()
            ->compulsory()
            ->ask();

        if ($node) {
            $this->output->writeln("<info>The image will also contain NodeJS</info>");
            $node = $this->getAentHelper()
                ->choiceQuestion(
                    'NodeJS version',
                    $nodeVersions
                )
                ->setDefault('0')
                ->ask();
            $this->output->writeln("<info>You selected the version $node</info>");
            $node = '-' . $node;
        } else {
            $this->output->writeln('<info>The image will not contain NodeJS</info>');
        }

        $service->setImage("thecodingmachine/php:$phpVersion-v1-$variant$node");

        /************************ Root application path **********************/
        $this->output->writeln('Now, we need to find the root of your web application.');
        $appDirectory = $this->getAentHelper()->question('PHP application root directory (relative to the project root directory)')
            ->setHelpText('Your PHP application root directory is typically the directory that contains your composer.json file. It must be relative to the project root directory.')
            ->setValidator(function (string $appDirectory) {
                $appDirectory = trim($appDirectory, '/') ?: '.';
                $rootDir = Pheromone::getContainerProjectDirectory();

                $fullDir = $rootDir.'/'.$appDirectory;
                if (!is_dir($fullDir)) {
                    throw new \InvalidArgumentException('Could not find directory '.Pheromone::getHostProjectDirectory().'/'.$appDirectory);
                }
                return $appDirectory;
            })->ask();

        $this->output->writeln('<info>Your root PHP application directory is '.Pheromone::getHostProjectDirectory().'/'.$appDirectory.'</info>');

        $service->addBindVolume('./'.$appDirectory, '/var/www/html');

        /************************ Web application path **********************/
        if ($variant === 'apache') {
            $answer = $this->getAentHelper()->question('Do you have a public web folder that is not the root of your application?')
                ->yesNoQuestion()->setDefault('y')->ask();
            if ($answer) {
                $webDirectory = $this->getAentHelper()->question('Web directory (relative to the PHP application directory)')
                    ->setHelpText('Your PHP application web directory is typically the directory that contains your index.php file. It must be relative to the PHP application directory ('.Pheromone::getHostProjectDirectory().'/'.$appDirectory.')')
                    ->setValidator(function (string $webDirectory) use ($appDirectory) {
                        $webDirectory = trim($webDirectory, '/') ?: '.';
                        $rootDir = Pheromone::getContainerProjectDirectory();

                        $fullDir = $rootDir.'/'.$appDirectory.'/'.$webDirectory;
                        if (!is_dir($fullDir)) {
                            throw new \InvalidArgumentException('Could not find directory '.Pheromone::getHostProjectDirectory().'/'.$appDirectory.'/'.$webDirectory);
                        }
                        return $webDirectory;
                    })->ask();
                $service->addImageEnvVariable('APACHE_DOCUMENT_ROOT', $webDirectory);
                $this->output->writeln('<info>Your web directory is '.Pheromone::getHostProjectDirectory().'/'.$appDirectory.'/'.$webDirectory.'</info>');
            }
        }

        /************************ Upload path **********************/
        $this->output->writeln('Now, we need to know if there are directories you want to store <info>out of the container</info>.');
        $this->output->writeln('When a container is removed, anything in it is lost. If your application is letting users upload files, or if it generates files, it might be important to <comment>store those files out of the container</comment>.');
        $this->output->writeln('If you want to mount such a directory out of the container, please specify the directory path below. Path must be relative to the PHP application root directory.');

        $uploadDirs = [];
        do {
            $uploadDirectory = $this->getAentHelper()
                ->question('Please input directory (for instance for file uploads) that you want to mount out of the container? (keep empty to ignore)')
                ->setDefault('')
                ->ask();
            $uploadDirectory = trim($uploadDirectory, '/');
            $rootDir = Pheromone::getContainerProjectDirectory();
            if ($uploadDirectory !== '') {
                $fullDir = $rootDir.'/'.$appDirectory.'/'.$uploadDirectory;
                if (!is_dir($fullDir)) {
                    $this->output->writeln('<error>Could not find directory '.Pheromone::getHostProjectDirectory().'/'.$appDirectory.'/'.$uploadDirectory.'</error>');
                    $uploadDirectory = null;
                } else {
                    $uploadDirs[] = $uploadDirectory;
                    $this->output->writeln('<info>Directory '.Pheromone::getHostProjectDirectory().'/'.$appDirectory.'/'.$uploadDirectory.' will be stored out of the container</info>');

                    $volumeName = $this->getAentHelper()
                        ->question('Please input directory (for instance for file uploads) that you want to mount out of the container? (keep empty to ignore)')
                        ->setDefault('')
                        ->compulsory()
                        ->setValidator(function (string $value) {
                            $value = trim($value);
                            if (!\preg_match('/^[a-zA-Z0-9_.-]+$/', $value)) {
                                throw new \InvalidArgumentException('Invalid volume name "' . $value . '". Volume names can contain alphanumeric characters, and "_", ".", "-".');
                            }
                            return $value;
                        })
                        ->ask();
                    $question = new Question('What name should we use for this volume? ', '');
                    $question->setValidator(function (string $value) {
                        $value = trim($value);
                        if (!\preg_match('/^[a-zA-Z0-9_.-]+$/', $value)) {
                            throw new \InvalidArgumentException('Invalid volume name "'.$value.'". Volume names can contain alphanumeric characters, and "_", ".", "-".');
                        }
                        return $value;
                    });
                    $service->addNamedVolume($volumeName, $appDirectory.'/'.$uploadDirectory);
                }
            }
        } while ($uploadDirectory !== '');

        $availableExtensions = ['amqp', 'ast', 'bcmath', 'bz2', 'calendar', 'dba', 'enchant', 'ev', 'event', 'exif',
            'gd', 'gettext', 'gmp', 'igbinary', 'imap', 'intl', 'ldap', 'mcrypt', 'memcached', 'mongodb', 'pcntl',
            'pdo_dblib', 'pdo_pgsql', 'pgsql', 'pspell', 'shmop', 'snmp', 'sockets', 'sysvmsg', 'sysvsem', 'sysvshm',
            'tidy', 'wddx', 'weakref', 'xdebug', 'xmlrpc', 'xsl', 'yaml'];

        $this->output->writeln('By default, the following extensions are enabled:');
        $this->output->writeln('<info>apcu mysqli opcache pdo pdo_mysql redis zip soap mbstring ftp mysqlnd</info>');
        $this->output->writeln('You can select more extensions below:');
        $this->output->writeln('<info>'.\implode(' ', $availableExtensions).'</info>');

        /************************ Extensions **********************/
        $extensions = [];
        do {
            $question = new Question('Please enter the name of an additional extension you want to install (keep empty to skip): ', '');
            $question->setAutocompleterValues($availableExtensions);
            $question->setValidator(function (string $value) use ($availableExtensions) {
                if (trim($value) !== '' && !\in_array($value, $availableExtensions)) {
                    throw new \InvalidArgumentException('Unknown extension '.$value);
                }
                return trim($value);
            });
            $extension = $this->getHelper('question')->ask($this->input, $this->output, $question);
            if ($extension !== '') {
                $service->addImageEnvVariable('PHP_EXTENSION_'.\strtoupper($extension), '1');
                $extensions[] = $extension;
            }
        } while ($extension !== '');
        $this->output->writeln('<info>Enabled extensions: apcu mysqli opcache pdo pdo_mysql redis zip soap mbstring ftp mysqlnd '.\implode(' ', $extensions).'</info>');

        /************************ php.ini settings **********************/
        $this->output->writeln("Now, let's customize some settings of <info>php.ini</info>.");

        $memoryLimit = $this->getAentHelper()->question('PHP <info>memory limit</info> (keep empty to stay with the default 128M)')
            ->setHelpText('This value will be used in the memory_limit option of PHP via the PHP_INI_MEMORY_LIMIT environment variable.')
            ->setValidator(function (string $value) {
                if (trim($value) !== '' && !\preg_match('/^[0-9]+([MGK])?$/i', $value)) {
                    throw new \InvalidArgumentException('Invalid value: '.$value);
                }
                return trim($value);
            })
            ->ask();
        if ($memoryLimit !== '') {
            $this->output->writeln("<info>Memory limit: $memoryLimit</info>");
            $service->addImageEnvVariable('PHP_INI_MEMORY_LIMIT', $memoryLimit);
        }

        $uploadMaxFileSize = $this->getAentHelper()->question('<info>Maximum file size for uploaded files</info> (keep empty to stay with the default 2M)')
            ->setHelpText('This value will be used in the upload_max_file_size and post_max_size options of PHP via the PHP_INI_UPLOAD_MAX_FILESIZE and PHP_INI_POST_MAX_SIZE environment variables.')
            ->setValidator(function (string $value) {
                if (trim($value) !== '' && !\preg_match('/^[0-9]+([MGK])?$/i', $value)) {
                    throw new \InvalidArgumentException('Invalid value: '.$value);
                }
                return trim($value);
            })
            ->ask();

        if ($uploadMaxFileSize !== '') {
            $this->output->writeln("<info>Upload maximum file size: $uploadMaxFileSize</info>");
            $service->addImageEnvVariable('PHP_INI_UPLOAD_MAX_FILESIZE', $uploadMaxFileSize);
            $service->addImageEnvVariable('PHP_INI_POST_MAX_SIZE', $uploadMaxFileSize);
        }

        $this->output->writeln('Does your service depends on another service to start? For instance a "mysql" instance?');
        do {
            $depend = $this->getAentHelper()
                ->question('Please input a service name your application depends on (keep empty to skip)')
                ->setDefault('')
                ->ask();
            if ($depend !== '') {
                $service->addDependsOn($depend);
                $this->output->writeln('<info>Added dependency: '.$depend.'</info>');
            }
        } while ($depend !== '');
        


        // TODO: propose to run composer install on startup?

        if ($variant === 'apache') {
            $service->addInternalPort(80);
            $service->setNeedVirtualHost(true);
            // $commentEvents->dispatchNewVirtualHost($serviceName);
        }

        $commentEvents->dispatchService($service);
        // $commentEvents->dispatchImage($service);

        return null;
    }

    /**
     * @return array[] An array with 3 keys: phpVersions, variants and nodeVersions
     */
    private function getAvailableVersionParts() : array
    {
        $registryClient = new RegistryClient();
        $tags = $registryClient->getImageTagsOnDockerHub('thecodingmachine/php');

        $phpVersions = [];
        $variants = [];
        $nodeVersions = [];

        foreach ($tags as $tag) {
            $parts = \explode('-', $tag);
            if (count($parts) < 3) {
                continue;
            }
            if ($parts[1] !== 'v1') {
                continue;
            }
            $phpVersions[$parts[0]] = true;
            $variants[$parts[2]] = true;
            if (isset($parts[3])) {
                $nodeVersions[$parts[3]] = true;
            }
        }

        $phpVersions = \array_keys($phpVersions);
        $variants = \array_keys($variants);
        $nodeVersions = \array_keys($nodeVersions);

        rsort($phpVersions);
        sort($variants);
        rsort($nodeVersions, SORT_NUMERIC);

        return [
            'phpVersions' => $phpVersions,
            'variants' => $variants,
            'nodeVersions' => $nodeVersions,
        ];
    }
}
