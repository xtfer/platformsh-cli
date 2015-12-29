<?php
namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SshKeyAddCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('ssh-key:add')
            ->setDescription('Add a new SSH key')
            ->addArgument('path', InputArgument::OPTIONAL, 'The path to an existing SSH key')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'A name to identify the key');
        $this->addExample('Add an existing public key', '~/.ssh/id_rsa.pub');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        /** @var \Platformsh\Cli\Helper\ShellHelperInterface $shellHelper */
        $shellHelper = $this->getHelper('shell');
        $shellHelper->setOutput($this->stdErr);

        $publicKeyPath = $input->getArgument('path');
        if (empty($publicKeyPath)) {
            $defaultKeyPath = $this->getDefaultKeyPath();
            $defaultPublicKeyPath = $defaultKeyPath . '.pub';

            // Look for an existing local key.
            if (file_exists($defaultPublicKeyPath)
                && $questionHelper->confirm("Use existing local key <info>" . basename($defaultPublicKeyPath) . "</info>?", $input, $output)) {
                $publicKeyPath = $defaultPublicKeyPath;
            }
            // Offer to generate a key.
            elseif ($shellHelper->commandExists('ssh-keygen')
                && $questionHelper->confirm("Generate a new key?", $input, $this->stdErr)) {
                $newKeyPath = $this->getNewKeyPath($defaultKeyPath);
                $args = ['ssh-keygen', '-t', 'rsa', '-f', $newKeyPath, '-N', ''];
                $shellHelper->execute($args, null, true);
                $publicKeyPath = $newKeyPath . '.pub';
                $this->stdErr->writeln("Generated a new key: $publicKeyPath");

                if (!in_array(basename($newKeyPath), ['id_rsa', 'id_dsa'])) {
                    $this->stdErr->writeln('Add this key to an SSH agent with:');
                    $this->stdErr->writeln('    eval $(ssh-agent)');
                    $this->stdErr->writeln('    ssh-add ' . escapeshellarg($newKeyPath));
                }
            }
            else {
                $this->stdErr->writeln("<error>You must specify the path to a public SSH key</error>");
                return 1;
            }
        }

        if (!file_exists($publicKeyPath)) {
            $this->stdErr->writeln("File not found: <error>$publicKeyPath<error>");
            return 1;
        }

        // Use ssh-keygen to help validate the key.
        if ($shellHelper->commandExists('ssh-keygen')) {
            // Newer versions of ssh-keygen require the -E argument to get an
            // MD5 fingerprint. Older versions of ssh-keygen return an MD5
            // fingerprint anyway.
            $oldArgs = ['ssh-keygen', '-l', '-f', $publicKeyPath];
            $newArgs = array_merge($oldArgs, ['-E', 'md5']);
            $result = $shellHelper->execute($newArgs, null, false);
            if ($result === false) {
                $result = $shellHelper->execute($oldArgs, null, false);
            }

            // If both commands failed, the key is not valid.
            if ($result === false) {
                $this->stdErr->writeln("The file does not contain a valid public key: <error>$publicKeyPath</error>");
                return 1;
            }

            // Extract the fingerprint from the command output.
            if (preg_match('/^\s*[0-9]+ +(MD5:)?([0-9a-z:]+)( |$)/i', $result, $matches)) {
                $fingerprint = str_replace(':', '', $matches[2]);
            }
            else {
                $this->debug("Unexpected output from ssh-keygen: $result");
            }

            // Check whether the public key already exists in the user's account.
            if (isset($fingerprint) && $this->keyExistsByFingerprint($fingerprint)) {
                $this->stdErr->writeln(
                    'An SSH key already exists in your Platform.sh account with the same fingerprint: ' . $fingerprint
                );
                $this->stdErr->writeln("List your SSH keys with: <info>platform ssh-keys</info>");

                return 0;
            }
        }

        // Get the public key content.
        $publicKey = file_get_contents($publicKeyPath);
        if ($publicKey === false) {
            $this->stdErr->writeln("Failed to read public key file: <error>$publicKeyPath</error>");
            return 1;
        }

        // Ask for a key name, if it's not specified by the --name option. It
        // will default to the machine's hostname.
        $name = $input->getOption('name');
        if (!$name) {
            $defaultName = gethostname() ?: null;
            $name = $questionHelper->askInput('Enter a name for the key', $input, $this->stdErr, $defaultName);
        }

        // Add the new key.
        $this->getClient()->addSshKey($publicKey, $name);

        $this->stdErr->writeln(
            'The SSH key <info>' . basename($publicKeyPath) . '</info> has been successfully added to your Platform.sh account.'
        );

        return 0;
    }

    /**
     * Check whether the SSH key already exists in the user's account.
     *
     * @param string $fingerprint The public key fingerprint (as an MD5 hash).
     *
     * @return bool
     */
    protected function keyExistsByFingerprint($fingerprint)
    {
        foreach ($this->getClient()->getSshKeys() as $existingKey) {
            if ($existingKey->fingerprint === $fingerprint) {
                return true;
            }
        }

        return false;
    }

    /**
     * The path to the user's key that we expect to be used with Platform.sh.
     *
     * @param string $basename
     *
     * @return string
     */
    protected function getDefaultKeyPath($basename = 'id_rsa')
    {
        return $this->getHomeDir() . '/.ssh/' . $basename;
    }

    /**
     * Find the path for a new SSH key.
     *
     * If the file already exists, this will recurse to find a new filename.
     *
     * @param string $base
     * @param int    $number
     *
     * @return string
     */
    protected function getNewKeyPath($base, $number = 1)
    {
        $base = $base ?: $this->getDefaultKeyPath();
        $filename = $base;
        if ($number > 1) {
            $base = $this->getDefaultKeyPath('platform_sh.key');
            $filename = strpos($base, '.key') ? str_replace('.key', ".$number.key", $base) : "$base.$number";
        }
        if (file_exists($filename)) {
            return $this->getNewKeyPath($base, ++$number);
        }

        return $filename;
    }

}
