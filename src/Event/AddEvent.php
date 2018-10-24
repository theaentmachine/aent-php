<?php

namespace TheAentMachine\AentPhp\Event;

use Safe\Exceptions\ArrayException;
use Safe\Exceptions\FilesystemException;
use Safe\Exceptions\StringsException;
use TheAentMachine\Aent\Event\Service\AbstractServiceAddEvent;
use TheAentMachine\Aent\Event\Service\Model\Environments;
use TheAentMachine\Aent\Event\Service\Model\ServiceState;
use TheAentMachine\Aenthill\Pheromone;
use TheAentMachine\Exception\MissingEnvironmentVariableException;
use TheAentMachine\Prompt\Helper\ValidatorHelper;
use TheAentMachine\Registry\RegistryClient;
use TheAentMachine\Service\Service;
use TheAentMachine\Service\Volume\BindVolume;
use TheAentMachine\Service\Volume\NamedVolume;
use function Safe\rsort;
use function Safe\sprintf;

final class AddEvent extends AbstractServiceAddEvent
{
    /**
     * @param Environments $environments
     * @return ServiceState[]
     * @throws MissingEnvironmentVariableException
     * @throws ArrayException
     * @throws StringsException
     * @throws FilesystemException
     */
    protected function createServices(Environments $environments): array
    {
        $service = new Service();
        $service->setServiceName($this->prompt->getPromptHelper()->getServiceName());
        $service->setImage($this->getImage());
        $rootDirectoryVolume = $this->getRootDirectoryVolume();
        $service->addBindVolume($rootDirectoryVolume->getSource(), $rootDirectoryVolume->getTarget());
        $apacheDocumentRoot = $this->getApacheDocumentRoot($rootDirectoryVolume->getSource());
        if (!empty($apacheDocumentRoot)) {
            $service->addImageEnvVariable('APACHE_DOCUMENT_ROOT', $apacheDocumentRoot);
        }
        $namedVolumes = $this->getNamedVolumes($rootDirectoryVolume->getSource());
        foreach ($namedVolumes as $namedVolume) {
            $service->addNamedVolume($namedVolume->getSource(), $namedVolume->getTarget());
        }
        $extensions = $this->getPHPExtensions();
        foreach ($extensions as $extension) {
            $service->addImageEnvVariable('PHP_EXTENSION_' . \strtoupper($extension), '1');
        }
        $service->addImageEnvVariable('PHP_INI_MEMORY_LIMIT', '1G');
        $service->addImageEnvVariable('PHP_INI_UPLOAD_MAX_FILESIZE', '50M');
        $service->addImageEnvVariable('PHP_INI_POST_MAX_SIZE', '50M');
        $service->addInternalPort(80);
        $service->addVirtualHost(80);
        $service->setNeedBuild(true);
        $developmentVersion = clone $service;
        $developmentVersion->addContainerEnvVariable('STARTUP_COMMAND_1', 'composer install', 'This command will be automatically launched on container startup');
        $remoteVersion = clone $service;
        $remoteVersion->addDockerfileCommand(sprintf('FROM %s', $service->getImage()));
        $remoteVersion->addDockerfileCommand(sprintf('COPY --chown=docker:docker %s .', $rootDirectoryVolume->getSource()));
        $remoteVersion->addDockerfileCommand('RUN composer install');
        if (strpos($service->getImage() ?? '', 'node') !== false) {
            $remoteVersion->addDockerfileCommand('RUN yarn install');
        }
        $serviceState = new ServiceState($developmentVersion, $remoteVersion, $remoteVersion);
        return [$serviceState];
    }

    /**
     * @return string
     * @throws ArrayException
     */
    private function getImage(): string
    {
        [
            'phpVersions' => $phpVersions,
            'nodeVersions' => $nodeVersions,
        ] = $this->getAvailableVersionParts();
        $phpVersion = $this->prompt->select("\nPHP version", $phpVersions, null, $phpVersions[0], true) ?? '';
        $variant = 'apache';
        $withNode = $this->prompt->confirm("\nDo you want to use Node.js for building your frontend source code?", null, null, true);
        $node = '';
        if ($withNode) {
            $node = $this->prompt->select("\nNode.js version", $nodeVersions, null, $nodeVersions[0], true) ?? '';
            $this->output->writeln("\n👌 Alright, I'm going to use PHP <info>$phpVersion</info> with Node.js <info>$node</info>!");
            $node = '-' . $node;
        } else {
            $this->output->writeln("\n👌 Alright, I'm going to use PHP <info>$phpVersion</info> without Node.js!");
        }
        return "thecodingmachine/php:$phpVersion-v1-$variant$node";
    }

    /**
     * @return mixed[] An array with 2 keys: phpVersions and nodeVersions
     * @throws ArrayException
     */
    private function getAvailableVersionParts() : array
    {
        $registryClient = new RegistryClient();
        $tags = $registryClient->getImageTagsOnDockerHub('thecodingmachine/php');
        $phpVersions = [];
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
            if (isset($parts[3])) {
                $nodeVersions[$parts[3]] = true;
            }
        }
        $phpVersions = \array_keys($phpVersions);
        $nodeVersions = \array_keys($nodeVersions);
        rsort($phpVersions);
        rsort($nodeVersions, SORT_NUMERIC);
        return [
            'phpVersions' => $phpVersions,
            'nodeVersions' => $nodeVersions,
        ];
    }

    /**
     * @return BindVolume
     */
    private function getRootDirectoryVolume(): BindVolume
    {
        $text = "\n<info>PHP application directory</info> (relative to the project root directory)";
        $helpText = "Your <info>PHP application directory</info> is typically the directory that contains your <info>composer.json file</info>. It must be relative to the project root directory.";
        return $this->prompt->getPromptHelper()->getBindVolume($text, '/var/www/html', $helpText);
    }

    /**
     * @param string $rootDirectory
     * @return null|string
     * @throws MissingEnvironmentVariableException
     * @throws StringsException
     */
    private function getApacheDocumentRoot(string $rootDirectory): ?string
    {
        $text = "\n<info>Apache document root</info> (relative to the PHP application directory - leave empty if it's the PHP application directory)";
        $helpText = sprintf(
            "The <info>Apache document root</info> is typically the directory that contains your <info>index.php</info> file. It must be relative to the PHP application directory (%s/%s).",
            Pheromone::getHostProjectDirectory(),
            $rootDirectory
        );
        return $this->prompt->input($text, $helpText, null, false, ValidatorHelper::getAlphaValidator()) ?? '';
    }

    /**
     * @param string $rootDirectory
     * @return NamedVolume[]
     */
    private function getNamedVolumes(string $rootDirectory): array
    {
        $this->output->writeln("\nNow, we need to know if there are directories you want to store <info>out of the container</info>.");
        $this->output->writeln('When a container is removed, anything in it is lost. If your application is letting users upload files, or if it generates files, it might be important to <comment>store those files out of the container</comment>.');
        $this->output->writeln('If you want to mount such a directory out of the container, please specify the directory path below. Path must be relative to the PHP application root directory.');
        $namedVolumes = [];
        do {
            $text = "\nDirectory (relative to root directory) you want to mount out of the container (keep empty to skip)";
            $dir = $this->prompt->input($text, null, null, false, ValidatorHelper::getAlphaValidator());
            if (!empty($dir)) {
                $namedVolumes[] = new NamedVolume($dir . '_data', $rootDirectory . '/' . $dir);
            }
        } while (!empty($dir));
        return $namedVolumes;
    }

    /**
     * @return string[]
     */
    private function getPHPExtensions(): array
    {
        $availableExtensions = ['amqp', 'ast', 'bcmath', 'bz2', 'calendar', 'dba', 'enchant', 'ev', 'event', 'exif',
            'gd', 'gettext', 'gmp', 'igbinary', 'imap', 'intl', 'ldap', 'mcrypt', 'memcached', 'mongodb', 'pcntl',
            'pdo_dblib', 'pdo_pgsql', 'pgsql', 'pspell', 'shmop', 'snmp', 'sockets', 'sysvmsg', 'sysvsem', 'sysvshm',
            'tidy', 'wddx', 'weakref', 'xdebug', 'xmlrpc', 'xsl', 'yaml'];
        $this->output->writeln("\nBy default, the following extensions are enabled:");
        $this->output->writeln('<info>apcu mysqli opcache pdo pdo_mysql redis zip soap mbstring ftp mysqlnd</info>');
        $this->output->writeln('You can select more extensions below:');
        $this->output->writeln('<info>' . \implode(' ', $availableExtensions) . '</info>');
        $extensions = [];
        do {
            $text = "\nExtension you want to install (keep empty to skip)";
            $extension = $this->prompt->autocompleter(
                $text,
                $availableExtensions,
                null,
                null,
                false,
                function (string $value) use ($availableExtensions) {
                    if (\trim($value) !== '' && !\in_array($value, $availableExtensions, true)) {
                        throw new \InvalidArgumentException('Unknown extension ' . $value);
                    }
                    return \trim($value);
                }
            );
            if (!empty($extension)) {
                $extensions[] = $extension;
            }
        } while (!empty($extension));
        $this->output->writeln("\n👌 Alright, I'm going to enable the following extensions: <info>apcu mysqli opcache pdo pdo_mysql redis zip soap mbstring ftp mysqlnd " . \implode(' ', $extensions) . '</info>');
        return $extensions;
    }
}
