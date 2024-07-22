<?php

namespace Flute\Modules\Stats\src\Driver\Items;

use Flute\Core\Database\Entities\Server;
use Flute\Core\Database\Entities\User;
use Flute\Core\Table\TableBuilder;
use Flute\Core\Table\TableColumn;
use Flute\Core\Table\TablePreparation;
use Flute\Modules\Stats\src\Contracts\DriverInterface;

class FirePlayerStatsDriver implements DriverInterface
{
    protected string $ranks = 'default';
    protected string $server_id = '1'; // sm_server_id from /cfg/sourcemod/FirePlayersStats.cfg

    public function __construct(array $config = [])
    {
        $this->ranks = $config['ranks'] ?? 'default';
        $this->server_id = $config['server_id'] ?? '1';
    }

    public function getSupportedMods(): array
    {
        return [730];
    }

    public function getBlocks(): array
    {
        return [
            'points' => [
                'text' => 'stats.profile.value',
                'icon' => 'ph-number-circle-five'
            ],
            'kills' => [
                'text' => 'stats.profile.kills',
                'icon' => 'ph-smiley-x-eyes'
            ],
            'deaths' => [
                'text' => 'stats.profile.deaths',
                'icon' => 'ph-skull'
            ],
            'shoots' => [
                'text' => 'stats.profile.shoots',
                'icon' => 'ph-fire'
            ],
            'hits' => [
                'text' => 'stats.profile.hits',
                'icon' => 'ph-target'
            ],
            'headshots' => [
                'text' => 'stats.profile.headshots',
                'icon' => 'ph-baby'
            ],
            'assists' => [
                'text' => 'stats.profile.assists',
                'icon' => 'ph-handshake'
            ],
            'round_win' => [
                'text' => 'stats.profile.round_win',
                'icon' => 'ph-trophy'
            ],
            'round_lose' => [
                'text' => 'stats.profile.round_lose',
                'icon' => 'ph-thumbs-down'
            ],
        ];
    }

    /**
     * Set columns for the table.
     */
    public function setColumns(TableBuilder $tableBuilder)
    {
        $tableBuilder->addColumn((new TableColumn('user_url'))->setVisible(false));
        $tableBuilder->addCombinedColumn('avatar', 'nickname', __('def.user'), 'user_url', true);

        $tableBuilder->addColumns([
            (new TableColumn('rank', __('stats.rank')))->image(false)->setOrderable(true)->setDefaultOrder(),
            (new TableColumn('points', __('stats.score')))->setType('text'),
            (new TableColumn('kills', __('stats.kills')))->setType('text'),
            (new TableColumn('deaths', __('stats.deaths')))->setType('text'),
        ]);
    }

    /**
     * Get data for the table.
     */
    public function getData(
        Server $server,
        string $dbname,
        int $page,
        int $perPage,
        int $draw,
        array $columns = [],
        array $search = [],
        array $order = []
    ): array {
        $select = $this->prepareSelectQuery($dbname, $columns, $search, $order);

        $paginator = new \Spiral\Pagination\Paginator($perPage);
        $paginate = $paginator->withPage($page)->paginate($select);

        $result = $select->fetchAll();

        $steamIds = $this->getSteamIds64($result);
        $usersData = steam()->getUsers($steamIds);

        $result = $this->mapUsersDataToResult($result, $usersData);

        return [
            'draw' => $draw,
            'recordsTotal' => $paginate->count(),
            'recordsFiltered' => $paginate->count(),
            'data' => TablePreparation::normalize(
                ['user_url', 'avatar', 'nickname', '', 'rank', 'points', 'kills', 'deaths'],
                $result
            )
        ];
    }

    public function getUserStats(int $sid, User $user): array
    {
        $steam = $this->getSteamId($user);

        if (!$steam) {
            return [];
        }

        try {
            $mode = dbmode()->getServerMode($this->getName(), $sid);

            $select = dbal()->database($mode->dbname)
                ->table('players')
                ->select()
                ->columns([
                    'servers_stats.points',
                    'servers_stats.kills',
                    'servers_stats.deaths',
                    'servers_stats.assists',
                    'servers_stats.round_win',
                    'servers_stats.round_lose',
                    'weapons_stats.shoots',
                    'weapons_stats.headshots',
                    'weapons_stats.hits_head',
                    'weapons_stats.hits_neck',
                    'weapons_stats.hits_chest',
                    'weapons_stats.hits_stomach',
                    'weapons_stats.hits_left_arm',
                    'weapons_stats.hits_right_arm',
                    'weapons_stats.hits_left_leg',
                    'weapons_stats.hits_right_leg'
                ])
                ->where('steam_id', 'like', "%" . $steam)
                ->innerJoin('servers_stats')->on('servers_stats.account_id', "players.account_id")
                ->innerJoin('weapons_stats')->on('weapons_stats.account_id', "players.account_id")
                ->fetchAll();

            if (empty($select)) {
                return [];
            }

            return $this->calculateStats($select, $mode->server);
        } catch (\Exception $e) {
            if (is_debug()) {
                throw $e;
            }

            return [];
        }
    }

    private function calculateStats(array $select, $server): array
    {
        $i = $shoots = $headshots = $hits = 0;
        foreach ($select as $val) {
            $shoots += $val['shoots'];
            $headshots += $val['headshots'];
            $hits += $val['hits_head'] + $val['hits_neck'] + $val['hits_chest'] + $val['hits_stomach']
                + $val['hits_left_arm'] + $val['hits_right_arm'] + $val['hits_left_leg'] + $val['hits_right_leg'];
            $i++;
        }

        $result = [
            'points' => $select[0]['points'],
            'kills' => $select[0]['kills'],
            'deaths' => $select[0]['deaths'],
            'shoots' => $shoots,
            'hits' => $hits,
            'headshots' => $headshots,
            'assists' => $select[0]['assists'],
            'round_win' => $select[0]['round_win'],
            'round_lose' => $select[0]['round_lose'],
        ];

        return [
            'server' => $server,
            'stats' => $result
        ];
    }

    private function prepareSelectQuery(string $dbname, array $columns, array $search, array $order): \Spiral\Database\Query\SelectQuery
    {
        $select = dbal()->database($dbname)->table('players')->select();
        $select->innerJoin('servers_stats')->on('servers_stats.account_id', "players.account_id");
        $select->onWhere('servers_stats.lastconnect', '!=', "-1"); // hide banned players in stats

        foreach ($columns as $column) {
            if ($column['searchable'] == 'true' && $column['search']['value'] != '') {
                $select->where($column['nickname'], 'like', "%" . $column['search']['value'] . "%");
            }
        }

        if (isset($search['value']) && !empty($search['value'])) {
            $select->where('nickname', 'like', "%" . $search['value'] . "%");
        }

        foreach ($order as $o) {
            $columnIndex = $o['column'];
            $columnName = $columns[$columnIndex]['name'];
            $direction = $o['dir'] === 'asc' ? 'ASC' : 'DESC';

            if ($columns[$columnIndex]['orderable'] == 'true') {
                $select->orderBy($columnName, $direction);
            }
        }

        return $select;
    }

    private function getSteamId(User $user): ?string
    {
        foreach ($user->socialNetworks as $social) {
            if ($social->socialNetwork->key === "Steam") {
                return $social->value;
            }
        }

        return null;
    }

    private function getSteamIds64(array $results): array
    {
        $steamIds64 = [];

        foreach ($results as $result) {
            try {
                $steamIds64[$result['steam_id']] = $result['steam_id'];
            } catch (\InvalidArgumentException $e) {
                logs()->error($e);
                unset($result); // Remove problematic result
            }
        }

        return $steamIds64;
    }

    private function mapUsersDataToResult(array $results, array $usersData): array
    {
        $mappedResults = [];

        foreach ($results as $result) {
            $steamId64 = $result['steam_id'];
            if (isset($usersData[$steamId64])) {
                $user = $usersData[$steamId64];
                $result['steam_id'] = $user->steamid;
                $result['avatar'] = $user->avatar;
            }

            if (isset($result['rank'])) {
                $result['rank'] = $this->getRankAsset((int) $result['rank']);
            }

            $result['user_url'] = url('profile/search/' . $result['steam_id'])->addParams([
                "else-redirect" => "https://steamcommunity.com/profiles/" . $result['steam_id']
            ])->get();

            $mappedResults[] = $result;
        }

        return $mappedResults;
    }

    protected function getRankAsset(int $rank)
    {
        return template()->getTemplateAssets()->rAssetFunction("Modules/Stats/Resources/assets/ranks/{$this->ranks}/{$rank}.webp");
    }

    /**
     * Return driver name
     */
    public function getName(): string
    {
        return "FirePlayerStats";
    }
}
