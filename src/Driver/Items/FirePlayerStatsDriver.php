<?php

namespace Flute\Modules\Stats\src\Driver\Items;

use Flute\Core\Database\Entities\Server;
use Flute\Core\Database\Entities\User;
use Flute\Core\Table\TableColumn;
use Flute\Core\Table\TablePreparation;
use Flute\Modules\Stats\src\Contracts\DriverInterface;

class FirePlayerStatsDriver implements DriverInterface
{
    protected string $ranks = 'default';
    protected string $server_id = '1'; // sm_fps_server_id from /cfg/sourcemod/FirePlayersStats.cfg

    public function __construct(array $config = [])
    {
        $this->ranks = isset ($config['ranks']) ? $config['ranks'] : 'default';
        $this->server_id = isset ($config['server_id']) ? $config['server_id'] : '1';
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
     * Возвращает столбцы для таблицы.
     * 
     * @return array[TableColumn] Массив столбцов таблицы.
     */
    public function getColumns(): array
    {
        return [
            (new TableColumn('avatar', ''))->image(),
            (new TableColumn('steam_id'))->setVisible(false),
            (new TableColumn('nickname', __('stats.name')))->setRender('{{KEY}}', "
                function(data, type, full) {
                    let a = make('a');
                    a.setAttribute('href', 'https://steamcommunity.com/profiles/'+full[1]);
                    a.setAttribute('target', '_blank');
                    a.innerHTML = data;
                    return a;
                }
            "),
            (new TableColumn('rank', __('stats.rank')))->image(false),
            (new TableColumn('points', __('stats.score')))->setType('text')->setOrderable(true)->setDefaultOrder(),
            (new TableColumn('kills', __('stats.kills')))->setType('text'),
            (new TableColumn('deaths', __('stats.deaths')))->setType('text'),
        ];
    }

    /**
     * Получает данные для таблицы.
     * 
     * @param Server $server Сущность сервера.
     * @param string $dbname Имя базы данных.
     * @param int $page Номер страницы.
     * @param int $perPage Количество элементов на странице.
     * @param int $draw Счетчик перерисовки.
     * @param array $columns Столбцы для фильтрации.
     * @param array $search Параметры поиска.
     * @param array $order Параметры сортировки.
     * 
     * @return array Ответ с данными.
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
                ['avatar', 'steam_id', 'nickname', 'rank', 'points', 'kills', 'deaths'],
                $result
            )
        ];
    }

    public function getUserStats(int $sid, User $user): array
    {
        $found = false;

        foreach ($user->socialNetworks as $social) {
            if ($social->socialNetwork->key === "Steam") {
                $found = $social->value;
            }
        }

        if (!$found)
            return [];

        try {
            $mode = dbmode()->getServerMode(self::class, $sid);
            $select = dbal()->database($mode->dbname)
                ->table('fps_players')
                ->select()
                ->columns(['fps_servers_stats.points', 'fps_servers_stats.kills', 'fps_servers_stats.deaths', 'fps_servers_stats.assists', 
                           'fps_servers_stats.round_win', 'fps_servers_stats.round_lose', 'fps_weapons_stats.shoots', 'fps_weapons_stats.headshots', 
                           'fps_weapons_stats.hits_head', 'fps_weapons_stats.hits_neck', 'fps_weapons_stats.hits_chest', 'fps_weapons_stats.hits_stomach', 
                           'fps_weapons_stats.hits_left_arm', 'fps_weapons_stats.hits_right_arm', 'fps_weapons_stats.hits_left_leg', 'fps_weapons_stats.hits_right_leg'])
                ->where('steam_id', 'like', "%" . $found)
                ->innerJoin('fps_servers_stats')->on('fps_servers_stats.account_id', "fps_players.account_id")
                ->innerJoin('fps_weapons_stats')->on('fps_weapons_stats.account_id', "fps_players.account_id")
                ->onWhere('fps_servers_stats.server_id', '=', $this->server_id);
                ->fetchAll();

            if (empty ($select))
                return [];

            // Необходимо для правильного подсчёта
            // В базе данных FirePlayerStats shoots, headshots, hits идут для каждого оружия по отдельности
            $i = $shoots = $headshots = $hits = 0;
            foreach ($select as $sel => $val) {
                $shoots = $shoots + $select[$i]['shoots'];
                $headshots = $headshots + $select[$i]['headshots'];
                $hits = $hits + $select[$i]['hits_head'] + $select[$i]['hits_neck'] + $select[$i]['hits_chest'] + $select[$i]['hits_stomach'] 
                              + $select[$i]['hits_left_arm'] + $select[$i]['hits_right_arm'] + $select[$i]['hits_left_leg'] + $select[$i]['hits_right_leg'];
                $i++;
            }


            $result['points'] = $select[0]['points'];
            $result['kills'] = $select[0]['kills'];
            $result['deaths'] = $select[0]['deaths'];
            $result['shoots'] = $shoots;
            $result['hits'] = $hits;
            $result['headshots'] = $headshots;
            $result['assists'] = $select[0]['assists'];
            $result['round_win'] = $select[0]['round_win'];
            $result['round_lose'] = $select[0]['round_lose'];

            unset($select, $i, $shoots, $headshots, $hits);

            return [
                'server' => $mode->server,
                'stats' => $result
            ];
        } catch (\Exception $e) {
            return [];
        }
        
    }

    private function getSteamIds64(array $results): array
    {
        $steamIds64 = [];
        foreach ($results as $result) {
            try {
                $steamIds64[$result['steam_id']] = $result['steam_id'];
            } catch (\InvalidArgumentException $e) {
                logs()->error($e);

                // Убираем чтобы не мешал.
                unset($result);
            }
        }
        return $steamIds64;
    }

    private function prepareSelectQuery(string $dbname, array $columns, array $search, array $order): \Spiral\Database\Query\SelectQuery
    {
        $select = dbal()->database($dbname)->table('fps_players')->select();
        $select->innerJoin('fps_servers_stats')->on('fps_servers_stats.account_id', "fps_players.account_id");
        $select->onWhere('fps_servers_stats.lastconnect', '!=', "-1"); // hide banned players in stats
        $select->andOnWhere('fps_servers_stats.server_id', '=', $this->server_id);
        
        foreach ($columns as $column) {
            if ($column['searchable'] == 'true' && $column['search']['value'] != '') {
                $select->where($column['nickname'], 'like', "%" . $column['search']['value'] . "%");
            }
        }

        if (isset ($search['value']) && !empty ($search['value'])) {
            $select->where('nickname', 'like', "%" . $search['value'] . "%");
        }

        foreach ($order as $order) {
            $columnIndex = $order['column'];
            $columnName = $columns[$columnIndex]['name'];
            $direction = $order['dir'] === 'asc' ? 'ASC' : 'DESC';

            if ($columns[$columnIndex]['orderable'] == 'true') {
                $select->orderBy($columnName, $direction);
            }
        }

        return $select;
    }

    private function mapUsersDataToResult(array $results, array $usersData): array
    {
        // Сопоставление Steam ID 64-битному формату и данных пользователя
        $mappedResults = [];

        foreach ($results as $result) {
            
            $steamId64 = $result['steam_id'];
            if (isset ($usersData[$steamId64])) {
                $user = $usersData[$steamId64];
                $result['steam_id'] = $usersData[$steamId64]->steamid;
                $result['avatar'] = $user->avatar;
            }

            if (isset ($result['rank'])) {
                $result['rank'] = $this->getRankAsset((int) $result['rank']);
            }
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
        return "FPS Driver";
    }
}