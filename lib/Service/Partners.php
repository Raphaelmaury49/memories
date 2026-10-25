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

use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\Constants;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Share\IManager as ShareManager;
use OCP\Share\IShare;

/**
 * Partner sharing: the owner's timeline folders are shared read-only with
 * the partner, whose timeline then includes them (optional, partner side).
 *
 * Built on regular Nextcloud user shares, so permissions, previews and
 * revocation behave exactly like any other share.
 */
final class Partners
{
    public function __construct(
        private IDBConnection $connection,
        private ShareManager $shareManager,
        private IRootFolder $rootFolder,
        private IUserManager $userManager,
        private SystemConfig $systemConfig,
        private Util $util,
    ) {}

    /**
     * People I share my timeline with, and people sharing theirs with me.
     *
     * @return array{sharing_with: list<array>, shared_with_me: list<array>}
     */
    public function list(): array
    {
        $uid = $this->util->getUID();
        $name = fn (string $u) => $this->userManager->getDisplayName($u) ?? $u;

        return [
            'sharing_with' => array_map(static fn ($r) => [
                'uid' => $r['partner'],
                'displayname' => $name($r['partner']),
            ], $this->rows('owner', $uid)),
            'shared_with_me' => array_map(static fn ($r) => [
                'uid' => $r['owner'],
                'displayname' => $name($r['owner']),
                'in_timeline' => (bool) $r['in_timeline'],
            ], $this->rows('partner', $uid)),
        ];
    }

    /**
     * Share all my timeline folders with $partner.
     *
     * @throws \InvalidArgumentException invalid partner or nothing to share
     */
    public function add(string $partner): void
    {
        $uid = $this->util->getUID();

        if ($partner === $uid || !$this->userManager->userExists($partner)) {
            throw new \InvalidArgumentException('Invalid partner');
        }

        if ([] !== array_filter($this->rows('owner', $uid), static fn ($r) => $r['partner'] === $partner)) {
            return;
        }

        $userFolder = $this->rootFolder->getUserFolder($uid);
        $ids = [];

        try {
            foreach ($this->systemConfig->getTimelinePaths($uid) as $path) {
                $rel = trim($path, '/');
                $node = '' === $rel ? $userFolder : $userFolder->get($rel);
                if (!$node instanceof Folder || $node->getPath() === $userFolder->getPath()) {
                    throw new \InvalidArgumentException('Set a timeline folder (not your whole drive) before adding a partner');
                }

                $share = $this->shareManager->createShare(
                    $this->shareManager->newShare()
                        ->setNode($node)
                        ->setShareType(IShare::TYPE_USER)
                        ->setSharedWith($partner)
                        ->setSharedBy($uid)
                        ->setPermissions(Constants::PERMISSION_READ),
                );
                $ids[] = $share->getFullId();
            }
        } catch (\Throwable $e) {
            foreach ($ids as $id) {
                $this->deleteShare($id);
            }

            throw $e instanceof \InvalidArgumentException ? $e : new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        if ([] === $ids) {
            throw new \InvalidArgumentException('No timeline folder to share');
        }

        $qb = $this->connection->getQueryBuilder();
        $qb->insert('memories_partners')->values([
            'owner' => $qb->createNamedParameter($uid),
            'partner' => $qb->createNamedParameter($partner),
            'share_ids' => $qb->createNamedParameter(implode(',', $ids)),
            'in_timeline' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
            'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
    }

    /**
     * Stop sharing with $partner and revoke the underlying shares.
     */
    public function remove(string $partner): void
    {
        $uid = $this->util->getUID();

        foreach ($this->rows('owner', $uid) as $row) {
            if ($row['partner'] !== $partner) {
                continue;
            }

            foreach (array_filter(explode(',', (string) $row['share_ids'])) as $id) {
                $this->deleteShare($id);
            }

            $qb = $this->connection->getQueryBuilder();
            $qb->delete('memories_partners')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $row['id'], IQueryBuilder::PARAM_INT)))
                ->executeStatement()
            ;
        }
    }

    /**
     * Partner side: show or hide an owner's photos in my timeline.
     */
    public function setInTimeline(string $owner, bool $show): void
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->update('memories_partners')
            ->set('in_timeline', $qb->createNamedParameter($show, IQueryBuilder::PARAM_BOOL))
            ->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
            ->andWhere($qb->expr()->eq('partner', $qb->createNamedParameter($this->util->getUID())))
            ->executeStatement()
        ;
    }

    /**
     * Add the shared folders of every partner shown in $uid's timeline.
     *
     * Shares that were revoked or declined are skipped silently.
     */
    public function addToRoot(TimelineRoot $root, string $uid): void
    {
        $rows = array_filter($this->rows('partner', $uid), static fn ($r) => (bool) $r['in_timeline']);
        if ([] === $rows) {
            return;
        }

        $userFolder = $this->rootFolder->getUserFolder($uid);

        foreach ($rows as $row) {
            foreach (array_filter(explode(',', (string) $row['share_ids'])) as $id) {
                try {
                    $share = $this->shareManager->getShareById($id, $uid);
                    $node = $userFolder->getFirstNodeById($share->getNodeId());
                    if ($node instanceof Folder) {
                        $root->addFolder($node);
                    }
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $column, string $uid): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('memories_partners')
            ->where($qb->expr()->eq($column, $qb->createNamedParameter($uid)))
        ;

        return $qb->executeQuery()->fetchAllAssociative();
    }

    private function deleteShare(string $id): void
    {
        try {
            $this->shareManager->deleteShare($this->shareManager->getShareById($id));
        } catch (\Throwable) {
        }
    }
}
