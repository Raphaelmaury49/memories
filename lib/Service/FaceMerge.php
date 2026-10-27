<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Raphaël Maury
 * @author Raphaël Maury
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Service;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\ClustersBackend\RecognizeBackend;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Merge suggestions for Recognize people: Recognize clusters faces incrementally
 * and never joins two existing clusters, so one person often ends up split.
 * Pairs are scored by average linkage (mean face-to-face euclidean distance).
 */
final class FaceMerge
{
    /** Default linkage cutoff for suggestions (face-api descriptors, same person < ~0.45). */
    public const float DEFAULT_THRESHOLD = 0.45;

    /** Faces sampled per cluster for the linkage. */
    private const int SAMPLE = 40;

    /** Centroid prefilter slack over the threshold. */
    private const float PREFILTER = 0.15;

    private const string DISMISSED_KEY = 'face_merge_dismissed';

    public function __construct(
        private IDBConnection $connection,
        private IConfig $config,
        private Util $util,
    ) {}

    /**
     * Candidate pairs of the same person, closest first.
     *
     * @return list<array{a: int, b: int, distance: float}>
     */
    public function suggestions(string $uid, ?float $threshold = null, int $limit = 50): array
    {
        $threshold ??= $this->threshold();
        $clusters = $this->loadClusters($uid);
        $dismissed = array_flip($this->dismissed($uid));

        $ids = array_keys($clusters);
        $pairs = [];

        $count = \count($ids);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $a = $ids[$i];
                $b = $ids[$j];

                if (isset($dismissed[self::pairKey($a, $b)])) {
                    continue;
                }

                if (self::dist($clusters[$a]['centroid'], $clusters[$b]['centroid']) > $threshold + self::PREFILTER) {
                    continue;
                }

                $d = self::linkage($clusters[$a]['sample'], $clusters[$b]['sample']);
                if ($d <= $threshold) {
                    $pairs[] = ['a' => $a, 'b' => $b, 'distance' => round($d, 4)];
                }
            }
        }

        usort($pairs, static fn ($x, $y) => $x['distance'] <=> $y['distance']);

        return \array_slice($pairs, 0, $limit);
    }

    /**
     * Move every face of $from into $to and drop $from.
     * $to keeps its name; it inherits $from's name and birthdate when it has none.
     *
     * @throws \InvalidArgumentException when a cluster is missing or not owned by $uid
     */
    public function merge(string $uid, int $from, int $to): void
    {
        if ($from === $to) {
            throw new \InvalidArgumentException('Cannot merge a person with itself');
        }

        $titles = $this->titles($uid, [$from, $to]);
        if (!\array_key_exists($from, $titles) || !\array_key_exists($to, $titles)) {
            throw new \InvalidArgumentException('Unknown person');
        }

        $this->util->transaction(function () use ($uid, $from, $to, $titles): void {
            $qb = $this->connection->getQueryBuilder();
            $qb->update('recognize_face_detections')
                ->set('cluster_id', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
                ->executeStatement()
            ;

            if ('' === $titles[$to] && '' !== $titles[$from]) {
                $qb = $this->connection->getQueryBuilder();
                $qb->update('recognize_face_clusters')
                    ->set('title', $qb->createNamedParameter($titles[$from]))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
                    ->executeStatement()
                ;
            }

            $qb = $this->connection->getQueryBuilder();
            $qb->delete('recognize_face_clusters')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->executeStatement()
            ;

            $this->moveBirthdate($uid, (string) $from, (string) $to);

            $qb = $this->connection->getQueryBuilder();
            $qb->delete('memories_covers')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('clustertype', $qb->createNamedParameter(RecognizeBackend::CLUSTER_TYPE)))
                ->andWhere($qb->expr()->eq('clusterid', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
                ->executeStatement()
            ;
        });
    }

    /**
     * Merge every suggestion under the threshold, largest cluster wins.
     * Pairs carrying two different names are left alone.
     *
     * @return list<array{from: int, to: int, distance: float}> merges done (or planned when $dryRun)
     */
    public function autoMerge(string $uid, float $threshold, bool $dryRun = false): array
    {
        $sizes = array_map(static fn ($c) => $c['count'], $this->loadClusters($uid));
        $titles = $this->titles($uid, array_keys($sizes));

        $parent = [];
        $find = static function (int $x) use (&$parent): int {
            while (isset($parent[$x])) {
                $x = $parent[$x];
            }

            return $x;
        };

        $done = [];
        foreach ($this->suggestions($uid, $threshold, PHP_INT_MAX) as $p) {
            $a = $find($p['a']);
            $b = $find($p['b']);
            if ($a === $b) {
                continue;
            }

            $ta = $titles[$a] ?? '';
            $tb = $titles[$b] ?? '';
            if ('' !== $ta && '' !== $tb && $ta !== $tb) {
                continue;
            }

            // Keep the named one, else the bigger one
            if ('' !== $ta && '' === $tb) {
                [$from, $to] = [$b, $a];
            } elseif ('' === $ta && '' !== $tb) {
                [$from, $to] = [$a, $b];
            } else {
                [$from, $to] = ($sizes[$a] ?? 0) >= ($sizes[$b] ?? 0) ? [$b, $a] : [$a, $b];
            }

            if (!$dryRun) {
                $this->merge($uid, $from, $to);
            }

            $parent[$from] = $to;
            $sizes[$to] = ($sizes[$to] ?? 0) + ($sizes[$from] ?? 0);
            $titles[$to] = '' !== ($titles[$to] ?? '') ? $titles[$to] : ($titles[$from] ?? '');
            $done[] = ['from' => $from, 'to' => $to, 'distance' => $p['distance']];
        }

        return $done;
    }

    /** Remember that two clusters are different people. */
    public function dismiss(string $uid, int $a, int $b): void
    {
        $list = $this->dismissed($uid);
        $list[] = self::pairKey($a, $b);
        $list = \array_slice(array_values(array_unique($list)), -2000);

        $this->config->setUserValue($uid, Application::APPNAME, self::DISMISSED_KEY, json_encode($list));
    }

    public function threshold(): float
    {
        $value = (float) $this->config->getSystemValue('memories.faces.merge_threshold', self::DEFAULT_THRESHOLD);

        return $value > 0 && $value < 2 ? $value : self::DEFAULT_THRESHOLD;
    }

    /**
     * Centroid, size and a deterministic sample of every clustered face set of the user.
     *
     * @return array<int, array{count: int, centroid: list<float>, sample: list<list<float>>}>
     */
    private function loadClusters(string $uid): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('d.cluster_id', 'd.face_vector')
            ->from('recognize_face_detections', 'd')
            ->innerJoin('d', 'recognize_face_clusters', 'c', $qb->expr()->eq('c.id', 'd.cluster_id'))
            ->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($uid)))
            ->orderBy('d.id')
        ;
        $res = $qb->executeQuery();

        $sum = [];
        $count = [];
        $sample = [];
        while ($row = $res->fetch()) {
            $vec = json_decode((string) $row['face_vector'], true);
            if (!\is_array($vec) || [] === $vec) {
                continue;
            }

            $id = (int) $row['cluster_id'];
            $vec = array_map('floatval', $vec);

            if (!isset($sum[$id])) {
                $sum[$id] = $vec;
                $count[$id] = 1;
                $sample[$id] = [$vec];

                continue;
            }

            foreach ($vec as $k => $v) {
                $sum[$id][$k] += $v;
            }
            ++$count[$id];

            // Reservoir sampling with a fixed seed per cluster keeps suggestions stable
            if (\count($sample[$id]) < self::SAMPLE) {
                $sample[$id][] = $vec;
            } else {
                $slot = crc32($id.':'.$count[$id]) % $count[$id];
                if ($slot < self::SAMPLE) {
                    $sample[$id][$slot] = $vec;
                }
            }
        }
        $res->closeCursor();

        $out = [];
        foreach ($sum as $id => $s) {
            $n = $count[$id];
            $out[$id] = [
                'count' => $n,
                'centroid' => array_map(static fn ($v) => $v / $n, $s),
                'sample' => $sample[$id],
            ];
        }

        return $out;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, string> cluster id => title ('' when unnamed), only clusters of $uid
     */
    private function titles(string $uid, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->select('id', 'title')
            ->from('recognize_face_clusters')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->in('id', $qb->createNamedParameter(array_values($ids), IQueryBuilder::PARAM_INT_ARRAY)))
        ;

        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $out[(int) $row['id']] = trim((string) $row['title']);
        }

        return $out;
    }

    private function moveBirthdate(string $uid, string $from, string $to): void
    {
        $get = function (string $id) use ($uid): bool {
            $qb = $this->connection->getQueryBuilder();
            $qb->select('cluster_id')
                ->from('memories_people')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('backend', $qb->createNamedParameter(RecognizeBackend::CLUSTER_TYPE)))
                ->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($id)))
                ->setMaxResults(1)
            ;

            return false !== $qb->executeQuery()->fetchOne();
        };

        $where = function (IQueryBuilder $qb) use ($uid, $from): void {
            $qb->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('backend', $qb->createNamedParameter(RecognizeBackend::CLUSTER_TYPE)))
                ->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($from)))
            ;
        };

        $qb = $this->connection->getQueryBuilder();
        if ($get($to)) {
            $qb->delete('memories_people');
        } else {
            $qb->update('memories_people')->set('cluster_id', $qb->createNamedParameter($to));
        }
        $where($qb);
        $qb->executeStatement();
    }

    /** @return list<string> */
    private function dismissed(string $uid): array
    {
        $list = json_decode($this->config->getUserValue($uid, Application::APPNAME, self::DISMISSED_KEY, '[]'), true);

        return \is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    private static function pairKey(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function dist(array $a, array $b): float
    {
        $s = 0.0;
        foreach ($a as $k => $v) {
            $d = $v - $b[$k];
            $s += $d * $d;
        }

        return sqrt($s);
    }

    /**
     * Mean pairwise distance between two face sets.
     *
     * @param list<list<float>> $a
     * @param list<list<float>> $b
     */
    private static function linkage(array $a, array $b): float
    {
        $s = 0.0;
        foreach ($a as $x) {
            foreach ($b as $y) {
                $s += self::dist($x, $y);
            }
        }

        return $s / (\count($a) * \count($b));
    }
}
