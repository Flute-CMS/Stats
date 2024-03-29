<?php

namespace Flute\Modules\Stats\src\Services;

use Flute\Core\Database\Entities\DatabaseConnection;
use Flute\Core\Database\Entities\Server;

class AdminStatsService
{
    public function store(string $mod, string $dbname, string $additional, int $sid)
    {
        $dbConnection = new DatabaseConnection();

        $dbConnection->mod = $mod;
        $dbConnection->dbname = $dbname;
        $dbConnection->additional = $additional;
        $dbConnection->server = $this->getServer($sid);

        transaction($dbConnection)->run();
    }

    public function update(int $id, string $mod, string $dbname, string $additional, int $sid)
    {
        $dbConnection = $this->find($id);

        $dbConnection->mod = $mod;
        $dbConnection->dbname = $dbname;
        $dbConnection->additional = $additional;

        transaction($dbConnection)->run();
    }

    public function delete(int $id): void
    {
        $dbConnection = $this->find($id);

        transaction($dbConnection, 'delete')->run();

        return;
    }

    /**
     * @return DatabaseConnection
     * 
     * @throws \Exception
     */
    public function find(int $id)
    {
        $item = rep(DatabaseConnection::class)->findByPK($id);

        if (!$item) {
            throw new \Exception(__('stats.not_found'));
        }

        return $item;
    }

    protected function getServer( int $id )
    {
        $server = rep(Server::class)->findByPK($id);

        if(empty($server)) {
            throw new \Exception(__('stats.server_not_found'));
        }

        return $server;
    }
}