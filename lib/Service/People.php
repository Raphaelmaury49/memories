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

use OCA\Memories\ClustersBackend\FaceRecognitionBackend;
use OCA\Memories\ClustersBackend\Manager;
use OCA\Memories\ClustersBackend\RecognizeBackend;
use OCA\Memories\Db\SQL;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;

/**
 * Per-user people metadata (birthdates) and multi-person timeline filtering
 * on top of the Recognize / Face Recognition clusters.
 */
final class People
{
    public const array BACKENDS = [RecognizeBackend::CLUSTER_TYPE, FaceRecognitionBackend::CLUSTER_TYPE];

    /** Max people combined in one "together" filter. */
    public const int MAX_TOGETHER = 8;

    public function __construct(
        private IDBConnection $connection,
        private IAppConfig $appConfig,
        private Util $util,
    ) {}

    /**
     * Stable key for a person of the current user, or null if it does not exist.
     *
     * Recognize clusters are keyed by numeric id (survives renames);
     * Face Recognition persons by name, unnamed clusters by id.
     */
    public function canonicalId(string $backend, string $name): ?string
    {
        $this->assertBackend($backend);
        $uid = $this->util->getUID();

        if (RecognizeBackend::CLUSTER_TYPE === $backend) {
            $qb = $this->connection->getQueryBuilder();
            $qb->select('id')
                ->from('recognize_face_clusters')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq(is_numeric($name) ? 'id' : 'title', $qb->createNamedParameter($name)))
                ->setMaxResults(1)
            ;
            $id = $qb->executeQuery()->fetchOne();

            return false === $id ? null : (string) $id;
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
            ->from('facerecog_persons')
            ->where($qb->expr()->eq('user', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq(is_numeric($name) ? 'id' : 'name', $qb->createNamedParameter($name)))
            ->setMaxResults(1)
        ;

        return false === $qb->executeQuery()->fetchOne() ? null : $name;
    }

    /**
     * Birthdates of the current user's people, keyed by canonical id.
     *
     * @param string[] $ids
     *
     * @return array<string, string>
     */
    public function birthdates(string $backend, array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if ([] === $ids) {
            return [];
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->select('cluster_id', 'birthdate')
            ->from('memories_people')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($this->util->getUID())))
            ->andWhere($qb->expr()->eq('backend', $qb->createNamedParameter($backend)))
            ->andWhere($qb->expr()->in('cluster_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->isNotNull('birthdate'))
        ;

        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $out[(string) $row['cluster_id']] = substr((string) $row['birthdate'], 0, 10);
        }

        return $out;
    }

    /**
     * Set or clear (null) the birthdate of one person of the current user.
     *
     * @throws \InvalidArgumentException on a malformed or future date
     */
    public function setBirthdate(string $backend, string $id, ?string $date): void
    {
        $this->assertBackend($backend);

        if (null !== $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
            if (false === $parsed || $parsed->format('Y-m-d') !== $date || $parsed > new \DateTimeImmutable('now')) {
                throw new \InvalidArgumentException('Invalid birthdate');
            }
        }

        $uid = $this->util->getUID();
        $where = function (IQueryBuilder $qb) use ($uid, $backend, $id): void {
            $qb->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('backend', $qb->createNamedParameter($backend)))
                ->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($id)))
            ;
        };

        $this->util->transaction(function () use ($where, $uid, $backend, $id, $date): void {
            $del = $this->connection->getQueryBuilder();
            $del->delete('memories_people');
            $where($del);
            $del->executeStatement();

            if (null === $date) {
                return;
            }

            $ins = $this->connection->getQueryBuilder();
            $ins->insert('memories_people')->values([
                'uid' => $ins->createNamedParameter($uid),
                'backend' => $ins->createNamedParameter($backend),
                'cluster_id' => $ins->createNamedParameter($id),
                'birthdate' => $ins->createNamedParameter(new \DateTimeImmutable($date.' 00:00:00', new \DateTimeZone('UTC')), IQueryBuilder::PARAM_DATE_IMMUTABLE),
            ]);
            $ins->executeStatement();
        });
    }

    /**
     * Parse "backend/name1,name2" into a backend and canonical ids of the current user.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function parseTogether(string $param): array
    {
        $parts = explode('/', $param, 2);
        if (2 !== \count($parts)) {
            throw new \InvalidArgumentException('Invalid together query');
        }

        [$backend, $names] = $parts;
        $this->assertBackend($backend);

        $names = array_values(array_unique(array_filter(array_map('trim', explode(',', $names)), static fn ($n) => '' !== $n)));
        if (\count($names) < 1 || \count($names) > self::MAX_TOGETHER) {
            throw new \InvalidArgumentException('Invalid number of people');
        }

        $ids = [];
        foreach ($names as $name) {
            $id = $this->canonicalId($backend, $name);
            if (null === $id) {
                throw new \InvalidArgumentException("Unknown person {$name}");
            }
            $ids[] = $id;
        }

        return [$backend, $ids];
    }

    /**
     * Restrict a timeline query to files showing every given person.
     *
     * @param list<string> $ids canonical ids from parseTogether
     */
    public function transformTogether(IQueryBuilder &$query, string $backend, array $ids): void
    {
        $uid = $this->util->getUID();

        foreach ($ids as $id) {
            $sub = $this->connection->getQueryBuilder();

            if (RecognizeBackend::CLUSTER_TYPE === $backend) {
                $sub->select($sub->expr()->literal(1))
                    ->from('recognize_face_detections', 'tgd')
                    ->innerJoin('tgd', 'recognize_face_clusters', 'tgc', $sub->expr()->eq('tgc.id', 'tgd.cluster_id'))
                    ->where($sub->expr()->eq('tgd.file_id', 'm.fileid'))
                    ->andWhere($sub->expr()->eq('tgc.id', $query->createNamedParameter((int) $id, IQueryBuilder::PARAM_INT)))
                    ->andWhere($sub->expr()->eq('tgc.user_id', $query->createNamedParameter($uid)))
                ;
            } else {
                $field = is_numeric($id) ? 'tgp.id' : 'tgp.name';
                $sub->select($sub->expr()->literal(1))
                    ->from('facerecog_images', 'tgi')
                    ->innerJoin('tgi', 'facerecog_faces', 'tgf', $sub->expr()->eq('tgf.image', 'tgi.id'))
                    ->innerJoin('tgf', 'facerecog_persons', 'tgp', $sub->expr()->eq('tgf.person', 'tgp.id'))
                    ->where($sub->expr()->eq('tgi.file', 'm.fileid'))
                    ->andWhere($sub->expr()->eq('tgi.model', $query->createNamedParameter($this->faceRecognitionModel(), IQueryBuilder::PARAM_INT)))
                    ->andWhere($sub->expr()->eq('tgp.user', $query->createNamedParameter($uid)))
                    ->andWhere($sub->expr()->eq($field, $query->createNamedParameter($id)))
                ;
            }

            $query->andWhere(SQL::exists($query, $sub));
        }
    }

    private function faceRecognitionModel(): int
    {
        return (int) $this->appConfig->getValueString('facerecognition', 'model', '-1');
    }

    private function assertBackend(string $backend): void
    {
        if (!\in_array($backend, self::BACKENDS, true)) {
            throw new \InvalidArgumentException("Invalid people backend {$backend}");
        }

        if (!Manager::get($backend)->isEnabled()) {
            throw new \InvalidArgumentException("{$backend} is not enabled");
        }
    }
}
