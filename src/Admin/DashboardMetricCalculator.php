<?php

namespace FlatRate\LiveChat\Admin;

/**
 * UTC window contract for the admin dashboard aggregates.
 *
 * The database query must preserve these boundaries. Deleted rows are excluded
 * from counts and from lifetime last activity. type 0 is a member message;
 * every other type is a system event.
 */
final class DashboardMetricCalculator
{
    /**
     * @return array{hour:string,day:string,week:string}
     */
    public static function bounds(int $now): array
    {
        return [
            'hour' => gmdate('Y-m-d H:i:s', $now - 3600),
            'day' => gmdate('Y-m-d H:i:s', $now - 86400),
            'week' => gmdate('Y-m-d H:i:s', $now - 604800),
        ];
    }

    /**
     * @param list<array{chat_id:int,type:int,user_id:int|null,created_at:string,deleted_by:int|null}> $rows
     * @return array<int,array{chat_id:int,messages_1h:int,messages_24h:int,messages_7d:int,event_messages_24h:int,unique_posters_24h:int,last_activity_at:?string}>
     */
    public static function aggregate(array $rows, int $now): array
    {
        $bounds = self::bounds($now);
        $out = [];
        foreach ($rows as $row) {
            if ($row['deleted_by'] !== null) {
                continue;
            }
            $id = (int) $row['chat_id'];
            if (!isset($out[$id])) {
                $out[$id] = [
                    'chat_id' => $id,
                    'messages_1h' => 0,
                    'messages_24h' => 0,
                    'messages_7d' => 0,
                    'event_messages_24h' => 0,
                    'unique_posters_24h' => 0,
                    'posters' => [],
                    'last_activity_at' => null,
                ];
            }
            $created = (string) $row['created_at'];
            if ($out[$id]['last_activity_at'] === null || $created > $out[$id]['last_activity_at']) {
                $out[$id]['last_activity_at'] = $created;
            }
            if ($created < $bounds['week']) {
                continue;
            }
            if ((int) $row['type'] === 0) {
                $out[$id]['messages_7d']++;
                if ($created >= $bounds['day']) {
                    $out[$id]['messages_24h']++;
                    if ($row['user_id'] !== null) {
                        $out[$id]['posters'][(string) $row['user_id']] = true;
                    }
                }
                if ($created >= $bounds['hour']) {
                    $out[$id]['messages_1h']++;
                }
                continue;
            }
            if ($created >= $bounds['day']) {
                $out[$id]['event_messages_24h']++;
            }
        }

        foreach ($out as &$row) {
            $row['unique_posters_24h'] = count($row['posters']);
            unset($row['posters']);
        }

        return $out;
    }
}
