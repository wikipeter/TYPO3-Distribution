<?php
namespace Helhum\TYPO3\SetupHandling\Composer\InstallerScript;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2017 Helmut Hummel <info@helhum.io>
 *  All rights reserved
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the text file GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Composer\Script\Event as ScriptEvent;
use Dotenv\Dotenv;
use Helhum\DotEnvConnector\Cache;
use Helhum\DotEnvConnector\DotEnvReader;
use Helhum\Typo3Console\Mvc\Cli\CommandDispatcher;
use Helhum\Typo3ConsolePlugin\InstallerScriptInterface;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

class SetupConfiguration implements InstallerScriptInterface
{
    /**
     * @var string
     */
    private $dotEnvFile;

    /**
     * @var string
     */
    private $dotEnvDistFile;

    /**
     * @var string
     */
    private $dotEnvInstallFile;

    public function __construct()
    {
        $this->dotEnvFile = getenv('TYPO3_PATH_COMPOSER_ROOT') . '/.env';
        $this->dotEnvDistFile = getenv('TYPO3_PATH_COMPOSER_ROOT') . '/.env.dist';
        $this->dotEnvInstallFile = getenv('TYPO3_PATH_COMPOSER_ROOT') . '/.env.install';
    }

    /**
     * @param ScriptEvent $event
     * @return bool
     */
    public function shouldRun(ScriptEvent $event)
    {
        return getenv('TYPO3_IS_SET_UP');
    }

    /**
     * Call the TYPO3 setup
     *
     * @param ScriptEvent $event
     * @throws \RuntimeException
     * @return bool
     * @internal
     */
    public function run(ScriptEvent $event)
    {
        $io = $event->getIO();
        $io->writeError('');
        $io->writeError('<info>Setting up TYPO3 Configuration</info>');

        $envConfig = file_get_contents($this->dotEnvDistFile);
        $installFileValues = $this->getParsedEnvFileValues($this->dotEnvInstallFile);
        if (!empty($installFileValues)) {
            $io->writeError('<info>Please provide some required settings for your distribution:</info>');
        }
        foreach ($installFileValues as $envName => $envValue) {
            if (StringUtility::beginsWith($envName, 'TYPO3_INSTALL_PROMPT_')
                && !StringUtility::endsWith($envName, '_DEFAULT')
            ) {
                $defaultValue = getenv($envName . '_DEFAULT') ?: null;
                do {
                    $answer = $event->getIO()->ask('<comment>' . $envValue . ($defaultValue ? sprintf(' (%s) :', $defaultValue) : ':') . '</comment> ', $defaultValue);
                } while ($answer === null);
                $envConfig = str_replace('${' . $envName . '}', $answer, $envConfig);
            }
        }

        $io->writeError('Generating .env file', true, $io::VERBOSE);
        $settings = $this->getSettings();
        foreach ($this->getParsedEnvFileValues($this->dotEnvDistFile) as $envName => $envValue) {
            if (StringUtility::beginsWith($envName, 'TYPO3__')) {
                try {
                    $configPath = str_replace(['TYPO3__', '__'], ['', '/'], $envName);
                    $value = ArrayUtility::getValueByPath($settings, $configPath);
                    $envConfig = str_replace($envName . '=""', $envName . '="' . $value . '"', $envConfig);
                    $settings = ArrayUtility::removeByPath($settings, $configPath);
                } catch (\RuntimeException $e) {
                }
            }
        }
        file_put_contents($this->dotEnvFile, $envConfig);

        $io->writeError('Merging project settings', true, $io::VERBOSE);
        $this->storeSettings($settings);
        $commandDispatcher = CommandDispatcher::createFromComposerRun($event);
        $commandDispatcher->executeCommand('settings:extract');
        $commandDispatcher->executeCommand('settings:dump', ['no-dev' => !$event->isDevMode()]);

        $io->writeError('');
        $io->writeError('<info>Your TYPO3 installation is now ready to use</info>');
        $io->writeError('');
        $io->writeError('Run <comment>vendor/bin/typo3cms server:run</comment> to start the PHP builtin webserver.');
        $io->writeError('');
        return true;
    }

    /**
     * @param string $dotEnvFile
     * @return array
     */
    private function getParsedEnvFileValues($dotEnvFile)
    {
        if (!file_exists($dotEnvFile)) {
            return [];
        }
        $envBackup = $_ENV;
        $dotEnvReader = new DotEnvReader(new Dotenv(dirname($dotEnvFile), basename($dotEnvFile)), new Cache(null, ''));
        $dotEnvReader->read();
        $modifiedEnvVars = array_diff_assoc($_ENV, $envBackup);
        foreach ($modifiedEnvVars as $name => $_) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        return $modifiedEnvVars;
    }

    private function getSettings()
    {
        $localConfValues = [];
        if (
            file_exists($localConfFile = getenv('TYPO3_PATH_ROOT') . '/typo3conf/LocalConfiguration.php')
            && false === strpos(file_get_contents($localConfFile), 'Auto generated by helhum/typo3-config-handling')
        ) {
            $localConfValues = require $localConfFile;
        }
        $settingsFile = getenv('TYPO3_PATH_COMPOSER_ROOT') . '/conf/settings.php';
        $settings = require $settingsFile;

        return array_replace_recursive($localConfValues, $settings);
    }

    private function storeSettings(array $settings)
    {
        $settingsFile = getenv('TYPO3_PATH_COMPOSER_ROOT') . '/conf/settings.php';
        file_put_contents(
            $settingsFile,
            '<?php return'
                . chr(10)
                . ArrayUtility::arrayExport($settings)
                . ';'
                . chr(10)
        );
    }
}
