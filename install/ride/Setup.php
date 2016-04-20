<?php
/*
 * This file is part of the WPStarter package.
 *
 * (c) Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace install\ride;

use Composer\Script\Event;
use Composer\Composer;


class Setup {

    public static $project;

    public static $composer;

    public static function run(Event $event) {

        Setup::$project = basename(getcwd());
        Setup::$composer = json_decode(file_get_contents('composer.json'), true);

        // Starter packages
        $packages = array(
            "ride/setup-cli" => "*",
            "ride/cli-web" => "*",
            "ride/cli-security" => "*",
            "ride/cli-database" => "*",
            "ride/setup-web" => "*",
            "ride/web-cms" => "*",
            "ride/web-cms-orm" => "*",
            "ride/theme-asphalt-cms" => "*",
            "ride/app-database" => "*",
            "mmucklo/krumo" =>  "*",
            "ride/web-security-orm" => "*",
            "ride/cli-orm" => "*",
            "smarty/smarty" =>  "dev-master#d3e26fb679081bc5f4427d86cb8d4275e835e094",
        );


        // Allow non https repository links
        Setup::$composer['config'] = array(
            'secure-http' => false
        );

        $instance = new static();

        $io = new IO($event->getIO(), 2);
        $io->block(array('Welcome to the Ride site'), 'red');

        $parameters = array();

        $availableLocales = array(1 => 'nl', 2 =>  'fr',3 => 'en');
        $defaultLocale = '3';
        $packages = $instance->installLocales($packages, $io, $availableLocales, $defaultLocale);

        // Local config
        $parameters = $instance->setLocalParams($parameters, $io);
        $createDatabase = $instance->createLocalDatabase($io);

        // Mandrill
        $parameters = $instance->installMandrill($parameters, $io);

         //Install all the modules!
        foreach ($packages as $key => $package) {
            Setup::$composer['require'][$key] = $package;
        }
        file_put_contents('composer.json', json_encode(Setup::$composer, JSON_PRETTY_PRINT));
        exec('composer update');
        // Write settings to parameters

        $instance->writeParams($parameters);
        if($createDatabase){
            exec('php application/cli.php database create ' . Setup::$project);
            exec('php application/cli.php od');
            exec('php application/cli.php og');
            $instance->setupContentManagerRole();
            $instance->setSecuredPaths();
        }
    }

    private function installLocales($packages, IO $io, $availableLocales, $defaultLocale) {
        $selectedLocales = $io->select(array('Select a locale'), $availableLocales, $defaultLocale, 3, "Invalid locale selected", true);
        $localesToInstall = array();
        if(is_array($selectedLocales)) {
            foreach($selectedLocales as $locale) {
                $localesToInstall[] = $availableLocales[$locale];
            }
        } else {
            $localesToInstall[] = $availableLocales[$selectedLocales];
        }
        foreach ($localesToInstall as $package) {
            $packages['ride/app-i18n-'. $package] = "*" ;
        }
        return $packages;
    }

    private function setLocalParams($parameters, IO $io) {
        $io->block(array('Local setup'), 'green');
        $useDefault = $io->askConfirmation(array('Do you want to use the standard development connection settings?'));
        if($useDefault) {
            //general parameters
            $parameters['dev'] = array(
                "cms.widget.offset" =>  300,
                "system.image" => "gd",
                "database.connection." . Setup::$project => 'mysql://root:root@127.0.0.1:3306/' . Setup::$project
            );
            return $parameters;
        } else {
            return;
        }
    }

    private function createLocalDatabase(IO $io) {
        return $io->askConfirmation(array('Create a local database based on these settings?'));
    }

    private function installMandrill($parameters, IO $io) {
        $io->block(array('Mandrill settings'), 'cyan');
        $useMandrill = $io->askConfirmation(array('Do you want to use Mandrill'));
        if($useMandrill) {
            Setup::$composer['require']['ride/app-mail-mandrill'] = "*";
            $subAccount = $io->ask(array('Enter your subaccount name'));
            $liveKey = $io->ask(array('Enter your Mandrill production API key'));
            $testKey = $io->ask(array('Enter your Mandrill test API key'));
            $parameters['general']['mail.mandrill.subaccount'] = $subAccount;
            $parameters['general']['mail.mandrill.apikey'] = $testKey;
            $parameters['prod']['mail.mandrill.apikey'] = $liveKey;
        }
        return $parameters;
    }

    private function writeParams($parameters) {
        $basePath = 'application/config/';
        foreach($parameters as $key =>$environment) {
            if($key == "general") {
                $key = '';
            }
            if(!file_exists(getcwd() . '/' .$basePath . $key)) {
                mkdir(getcwd() . '/' . $basePath . $key, true);
            }
            $data = $environment;
            file_put_contents($basePath . $key . '/parameters.json', json_encode($data, JSON_PRETTY_PRINT) );
            unset($data);
        }
    }
}
