<?php namespace std\dbdiff\controllers;

class Main extends \Controller
{
    private $sourceEnv;

    private $targetEnv;

    public function __create()
    {
        if ($direction = $this->data('direction')) {
            if ($envs = $this->parseDirection($direction)) {
                list($sourceEnv, $targetEnv) = $envs;

                $this->sourceEnv = $sourceEnv;
                $this->targetEnv = $targetEnv;
            }
        } else {
            $sourceEnvName = $this->data('source'); // todo [app:]env
            $targetEnvName = $this->data('target'); // todo [app:]env

            if ($sourceEnv = \ewma\apps\models\Env::where('name', $sourceEnvName)->first()) {
                $this->sourceEnv = $sourceEnv;
            }

            if ($targetEnv = \ewma\apps\models\Env::where('name', $targetEnvName)->first()) {
                $this->targetEnv = $targetEnv;
            }
        }
    }

    private function parseDirection($direction)
    {
        $exploded = explode('2', $direction);

        if (count($exploded) == 2) {
            $sourceEnvShortName = $exploded[0];
            $targetEnvShortName = $exploded[1];

            $sourceEnv = \ewma\apps\models\Env::where('short_name', $sourceEnvShortName)->first();
            $targetEnv = \ewma\apps\models\Env::where('short_name', $targetEnvShortName)->first();

            if ($sourceEnv && $targetEnv) {
                return [$sourceEnv, $targetEnv];
            }
        }
    }

    public function diff()
    {
        $sourceEnv = $this->sourceEnv;
        $targetEnv = $this->targetEnv;

        $type = $this->data('type');
        $outputFilePath = $this->_protected($sourceEnv->short_name . '2' . $targetEnv->short_name . '-' . $type . '.sql');

        mdir(dirname($outputFilePath));
        write($outputFilePath);

        $currentEnv = \ewma\apps\models\Env::where('app_id', 0)->where('name', $this->_env())->first();
        $currentServer = $currentEnv->server;

        $sourceServer = $sourceEnv->server;
        $targetServer = $targetEnv->server;

        $sourceDatabase = $this->data('source_database') ?: 'default';
        $targetDatabase = $this->data('source_database') ?: 'default';

        $sourceRemote = remote($sourceEnv->name);
        $targetRemote = remote($targetEnv->name);

        $sourceConfig = $sourceRemote->call('/ -:_appConfig:databases/' . $sourceDatabase);
        $targetConfig = $targetRemote->call('/ -:_appConfig:databases/' . $targetDatabase);

        $dbdiffPath = dataSets()->get('modules/std-dbdiff::dbdiff_path');

        $sourceServerHost = $currentServer == $sourceServer ? 'localhost' : $sourceServer->host;
        $targetServerHost = $currentServer == $targetServer ? 'localhost' : $targetServer->host;

        $command = [$dbdiffPath];

        $command[] = '--server1=' . $sourceConfig['user'] . ':' . $sourceConfig['pass'] . '@' . $sourceServerHost . ':3306';
        $command[] = '--server2=' . $targetConfig['user'] . ':' . $targetConfig['pass'] . '@' . $targetServerHost . ':3306';
        $command[] = '--type=' . $type;
        $command[] = '--output=' . $outputFilePath;
        $command[] = 'server1.' . $sourceConfig['name'] . ':server2.' . $targetConfig['name'];

        $command = implode(' ', $command);

        $commandHiddenPasswords = str_replace([$sourceConfig['pass'], $targetConfig['pass']], '********', $command);

        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $this->app->publicRoot);

        if (is_resource($process)) {
            $this->log($commandHiddenPasswords);

            while ($line = fgets($pipes[2]) or $line = fgets($pipes[1])) {
                $this->log(rtrim($line));
            }

            proc_close($process);
        }
    }
}
