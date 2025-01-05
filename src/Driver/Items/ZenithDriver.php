<?php

namespace Flute\Modules\Stats\src\Driver\Items;

use Flute\Core\Database\Entities\Server;
use Flute\Core\Database\Entities\User;
use Flute\Core\Table\TableBuilder;
use Flute\Core\Table\TableColumn;
use Flute\Core\Table\TablePreparation;
use Flute\Modules\Stats\src\Contracts\DriverInterface;

class ZenithDriver implements DriverInterface
{
    protected string $ranks = 'default';
    protected string $table = 'player_storage';

    public function __construct(array $config = [])
    {
        $this->ranks = $config['ranks'] ?? 'default';
        $this->table = $config['table'] ?? 'player_storage';
    }

    public function getSupportedMods() : array
    {
        return [730];
    }

    public function getBlocks() : array
    {
        return [
            'points' => [
                'text' => 'stats.score',
                'icon' => 'ph-number-circle-five'
            ],
            'rank' => [
                'text' => 'stats.rank',
                'icon' => 'ph-medal'
            ],
            'kills' => [
                'text' => 'stats.profile.kills',
                'icon' => 'ph-smiley-x-eyes'
            ],
            'deaths' => [
                'text' => 'stats.profile.deaths',
                'icon' => 'ph-skull'
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

    public function setColumns(TableBuilder $tableBuilder)
    {
        $tableBuilder->addColumn((new TableColumn('user_url'))->setVisible(false));
        $tableBuilder->addCombinedColumn('avatar', 'name', __('def.user'), 'user_url', true);

        $tableBuilder->addColumns([
            (new TableColumn('rank', __('stats.rank')))->setOrderable(true)->setDefaultOrder(),
            (new TableColumn('points', __('stats.score')))->setType('text')->setDefaultOrder(),
            (new TableColumn('kills', __('stats.kills')))->setType('text'),
            (new TableColumn('deaths', __('stats.deaths')))->setType('text'),
            // (new TableColumn('headshots', __('stats.headshots')))->setType('text'),
            // (new TableColumn('assists', __('stats.assists')))->setType('text'),
            // (new TableColumn('round_win', __('stats.round_win')))->setType('text'),
            // (new TableColumn('round_lose', __('stats.round_lose')))->setType('text'),
            (new TableColumn('last_online', __('stats.last_active')))->setType('text'),
        ]);
    }

    public function getData(
        Server $server,
        string $dbname,
        int $page,
        int $perPage,
        int $draw,
        array $columns = [],
        array $search = [],
        array $order = []
    ) : array {
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
                ['user_url', 'avatar', 'name', '', 'rank', 'points', 'kills', 'deaths', 'last_online'],
                $result
            )
        ];
    }

    public function getUserStats(int $sid, User $user) : array
    {
        $steam = $this->getSteamId($user);

        if (!$steam) {
            return [];
        }

        try {
            $mode = dbmode()->getServerMode($this->getName(), $sid);

            $select = dbal()->database($mode->dbname)
                ->table($this->table)
                ->select()
                ->where('steam_id', 'like', "%" . substr($steam, 10))
                ->fetchAll();

            if (empty($select)) {
                return [];
            }

            $stats = $this->parseUserStats($select[0]);

            return [
                'server' => $mode->server,
                'stats' => $stats
            ];
        } catch (\Exception $e) {
            if (is_debug()) {
                throw $e;
            }

            return [];
        }
    }

    private function prepareSelectQuery(string $dbname, array $columns, array $search, array $order) : \Spiral\Database\Query\SelectQuery
    {
        $select = dbal()->database($dbname)->table($this->table)->select();

        foreach ($columns as $column) {
            if ($column['searchable'] === 'true' && $column['search']['value'] !== '') {
                $select->where($column['name'], 'like', "%" . $column['search']['value'] . "%");
            }
        }

        if (isset($search['value']) && !empty($search['value'])) {
            $value = $search['value'];

            $select->where(function ($select) use ($value) {
                $select->where('steam_id', $value)
                    ->orWhere('name', 'like', "%" . $value . "%");
            });
        }

        // foreach ($order as $v) {
        //     $columnIndex = $v['column'];
        //     $columnName = $columns[$columnIndex]['name'];
        //     $direction = $v['dir'] === 'asc' ? 'ASC' : 'DESC';

        //     if ($columns[$columnIndex]['orderable'] === 'true' && $columnName !== 'rank') {
        //         $select->orderBy($columnName, $direction);
        //     }
        // }

        return $select;
    }

    private function getSteamIds64(array $results) : array
    {
        $steamIds64 = [];

        foreach ($results as $result) {
            try {
                $steamId64 = steam()->steamid($result['steam_id'])->ConvertToUInt64();
                $steamIds64[$result['steam_id']] = $steamId64;
            } catch (\InvalidArgumentException $e) {
                logs()->error($e);
            }
        }

        return $steamIds64;
    }

    private function mapUsersDataToResult(array $results, array $usersData) : array
    {
        $mappedResults = [];

        foreach ($results as $result) {
            $steamId32 = $result['steam_id'];
            if (isset($usersData[$steamId32])) {
                $user = $usersData[$steamId32];
                $result['steam_id'] = $user->steamid;
                $result['avatar'] = $user->avatar;
            }

            $ranks = json_decode($result['K4-Zenith-Ranks.storage'], true);
            $timeStats = json_decode($result['K4-Zenith-TimeStats.storage'], true);
            $stats = json_decode($result['K4-Zenith-Stats.storage'], true);

            $result['points'] = $ranks['Points'] ?? 0;
            $result['rank'] = $ranks['Rank']['Value'] ?? 'Unranked';
            $result['kills'] = $stats['Kills'] ?? 0;
            $result['deaths'] = $stats['Deaths'] ?? 0;
            $result['headshots'] = $stats['Headshots'] ?? 0;
            $result['assists'] = $stats['Assists'] ?? 0;
            $result['round_win'] = $stats['RoundWin'] ?? 0;
            $result['round_lose'] = $stats['RoundLose'] ?? 0;
            $result['last_online'] = (new \DateTimeImmutable($result['last_online']))->format(default_date_format());

            $result['user_url'] = url('profile/search/' . $result['steam_id'])->addParams([
                "else-redirect" => "https://steamcommunity.com/profiles/" . $result['steam_id']
            ])->get();

            $mappedResults[] = $result;
        }

        return $mappedResults;
    }

    private function getSteamId(User $user) : ?string
    {
        foreach ($user->socialNetworks as $social) {
            if ($social->socialNetwork->key === "Steam") {
                return $social->value;
            }
        }

        return null;
    }

    private function parseUserStats(array $data) : array
    {
        $ranks = json_decode($data['K4-Zenith-Ranks.storage'], true);
        $timeStats = json_decode($data['K4-Zenith-TimeStats.storage'], true);
        $stats = json_decode($data['K4-Zenith-Stats.storage'], true);

        return [
            'points' => $ranks['Points'] ?? 0,
            'rank' => $ranks['Rank']['Value'] ?? 'Unranked',
            'kills' => $stats['Kills'] ?? 0,
            'deaths' => $stats['Deaths'] ?? 0,
            'headshots' => $stats['Headshots'] ?? 0,
            'assists' => $stats['Assists'] ?? 0,
            'round_win' => $stats['RoundWin'] ?? 0,
            'round_lose' => $stats['RoundLose'] ?? 0,
            'last_online' => (new \DateTimeImmutable($data['last_online']))->format(default_date_format()),
        ];
    }

    protected function getRankAsset(int $rank)
    {
        return template()->getTemplateAssets()->rAssetFunction("Modules/Stats/Resources/assets/ranks/{$this->ranks}/{$rank}.webp");
    }

    public function getName() : string
    {
        return "Zenith";
    }
}
