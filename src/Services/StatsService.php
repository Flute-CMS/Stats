<?php

namespace Flute\Modules\Stats\src\Services;

use Flute\Core\Database\Entities\DatabaseConnection;
use Flute\Core\Database\Entities\User;
use Flute\Modules\Stats\src\Driver\DriverFactory;
use Flute\Modules\Stats\src\Driver\Items\FabiusDriver;
use Flute\Modules\Stats\src\Driver\Items\LevelsRanksDriver;
use Flute\Modules\Stats\src\Driver\Items\ZenithDriver;
use Flute\Modules\Stats\src\Exceptions\ModNotFoundException;
use Flute\Modules\Stats\src\Exceptions\ServerNotFoundException;

class StatsService
{
    protected array $serverModes = [];
    protected array $defaultDrivers = [
        LevelsRanksDriver::class,
        FabiusDriver::class,
        ZenithDriver::class,
    ];

    protected DriverFactory $driverFactory;
    protected const CACHE_KEY = 'flute.stats.servers';
    protected const CACHE_TIME = 3600;

    /**
     * Constructor for StatsService.
     *
     * @param DriverFactory $driverFactory Factory for creating driver instances.
     */
    public function __construct(DriverFactory $driverFactory)
    {
        $this->driverFactory = $driverFactory;

        $this->importDrivers();
        $this->importServers();
    }


    /**
     * Generates a table for the given server ID.
     *
     * @param int|null $sid Server ID.
     * @return mixed Table rendering result.
     * @throws \Exception If the module is not configured or server is not found.
     */
    public function generateTable(?int $sid = null)
    {
        $this->validateServerModes();

        $server = $this->getServerFromModes($sid);

        $factory = $this->getDriverFactory($server);

        $table = table(url("stats/get/{$server['server']->id}"));
        
        $factory->setColumns($table);

        return $table->render();
    }

    /**
     * Fetches the data for a specific server based on various parameters.
     *
     * @param int $page Page number.
     * @param int $perPage Number of items per page.
     * @param int $draw Draw counter.
     * @param array $columns Column configuration.
     * @param array $search Search configuration.
     * @param array $order Order configuration.
     * @param int|null $sid Server ID.
     * @return array Data from the driver.
     * @throws \Exception If the module is not configured or server is not found.
     */
    public function getData(
        int $page,
        int $perPage,
        int $draw,
        array $columns = [],
        array $search = [],
        array $order = [],
        ?int $sid = null
    ) {
        $this->validateServerModes();

        $server = $this->getServerFromModes($sid);

        $factory = $this->getDriverFactory($server);

        return $factory->getData(
            $server['server'],
            $server['db'],
            $page,
            $perPage,
            $draw,
            $columns,
            $search,
            $order
        );
    }

    public function getBlocks(?int $sid = null)
    {
        $this->validateServerModes();

        try {
            $serverConfig = $this->getServerFromModes($sid);

            $factory = $this->getDriverFactory($serverConfig);

            return $factory->getBlocks();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getUserStats(User $user, ?int $sid = null)
    {
        $this->validateServerModes();

        try {
            $serverConfig = $this->getServerFromModes($sid);

            $factory = $this->getDriverFactory($serverConfig);

            return $factory->getUserStats($serverConfig['server']->id, $user);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Retrieves the mode of a server by its ID.
     *
     * @param int $sid Server ID.
     * @return mixed Mode of the server.
     * @throws ModNotFoundException If the mode is not found.
     */
    public function getMode(int $sid)
    {
        $dbConnection = rep(DatabaseConnection::class)->findOne(['server_id' => $sid]);

        if (!$dbConnection)
            throw new ModNotFoundException($sid);

        return $dbConnection->mod;
    }

    /**
     * Get all server modes
     * 
     * @return array
     */
    public function getServerModes(): array
    {
        return $this->serverModes;
    }

    /**
     * Imports and registers drivers to the factory.
     */
    protected function importDrivers(): void
    {
        $driversNamespace = 'Flute\\Modules\\Stats\\src\\Driver\\Items\\';
        $driversPath = BASE_PATH . 'app/Modules/Stats/src/Driver/Items';

        $finder = finder()->files()->in($driversPath)->name('*.php');

        foreach ($finder as $file) {
            $className = $driversNamespace . $file->getBasename('.php');

            if (class_exists($className)) {
                $driverInstance = new $className();
                if (method_exists($driverInstance, 'getName')) {
                    $this->driverFactory->registerDriver($driverInstance->getName(), $className);
                }
            }
        }
    }

    /**
     * Imports server modes from the database and caches them if needed.
     */
    protected function importServers(): void
    {
        if (is_performance() && cache()->has(self::CACHE_KEY)) {
            $this->serverModes = cache()->get(self::CACHE_KEY);
            return;
        }

        $this->populateServerModes();

        if (is_performance()) {
            cache()->set(self::CACHE_KEY, $this->serverModes, self::CACHE_TIME);
        }
    }

    /**
     * Validates if server modes are configured.
     *
     * @throws \Exception If server modes are empty.
     */
    private function validateServerModes(): void
    {
        if (empty($this->serverModes)) {
            throw new \Exception(__('stats.module_is_not_configured'));
        }
    }

    /**
     * Gets server configuration from server modes based on server ID.
     *
     * @param int|null $sid Server ID.
     * @return array Server configuration.
     * @throws ServerNotFoundException
     * @throws ServerNotFoundException If the server is not found in the server modes.
     */
    public function getServerFromModes(?int $sid): array
    {
        if (!$sid) {
            $key = array_key_first($this->serverModes);

            $this->serverModes[$key]['current'] = true;

            return $this->serverModes[$key];
        }

        if (!isset($this->serverModes[$sid])) {
            throw new ServerNotFoundException($sid);
        }

        $this->serverModes[$sid]['current'] = true;

        return $this->serverModes[$sid];
    }

    /**
     * Creates a driver instance using the DriverFactory.
     *
     * @param array $server Server configuration.
     * @return mixed Instance of the driver.
     * @throws \Exception If unable to create driver.
     */
    private function getDriverFactory(array $server)
    {
        try {
            return $this->driverFactory->createDriver($server['factory'], json_decode(json_encode($server['additional']), true));
        } catch (\RuntimeException $e) {
            logs()->error($e);
            throw new \Exception(__('def.unknown_error'));
        }
    }

    /**
     * Populates server modes from the database.
     */
    private function populateServerModes(): void
    {
        $modes = rep(DatabaseConnection::class)->select()->load('server');
        $drivers = $this->driverFactory->getAllDrivers();

        foreach ($drivers as $key => $driver) {
            $modes->orWhere(function($query) use ($key, $driver) {
                return $query->where('mod', $key)->orWhere('mod', $driver);
            });
        }

        $modes = $modes->fetchAll();

        foreach ($modes as $mode) {
            if (!config("database.databases.{$mode->dbname}") || empty($mode->server)) {
                continue;
            }

            $additional = [];

            try {
                $additional = \Nette\Utils\Json::decode($mode->additional);
            } catch (\Exception $e) {
                $additional = [];
            }

            $this->serverModes[$mode->server->id] = [
                'server' => $mode->server,
                'db' => $mode->dbname,
                'factory' => $this->getServerModeDriver($mode->mod),
                'additional' => $additional,
            ];
        }
    }

    /**
     * Get the server mode driver normal key
     * 
     * @return string|null
     */
    protected function getServerModeDriver(string $driver)
    {
        $drivers = $this->driverFactory->getAllDrivers();

        if (isset($drivers[$driver])) {
            return $driver;
        }

        if ($search = array_search($driver, $drivers)) {
            return $search;
        }

        return null;
    }
}
