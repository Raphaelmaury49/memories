<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Controller;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\FsManager;
use OCA\Memories\Db\LensFolders;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\HttpResponseException;
use OCA\Memories\Service\Lens;
use OCA\Memories\Service\ServiceManager;
use OCA\Memories\Util;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Lock\LockedException;

final class LensController extends ApiController
{
    public function __construct(
        IRequest $request,
        protected IDBConnection $connection,
        protected TimelineQuery $tq,
        protected FsManager $fs,
        protected LensFolders $lensFolders,
        protected IClientService $clientService,
        protected Lens $lens,
        protected ServiceManager $serviceManager,
        protected Util $util,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    /**
     * Serve file metadata and, for GET, raw bytes to the Lens service account.
     *
     * @param int $fileid file ID to serve
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function file(int $fileid): Http\Response
    {
        return $this->util->guardEx(function () use ($fileid) {
            $this->serviceManager->guardLensServiceAccount();
            $file = $this->serviceManager->getServiceFile($fileid);

            $response = new Http\Response();
            if ('HEAD' !== $this->request->getMethod()) {
                $handle = $file->fopen('rb');
                if (false === $handle) {
                    throw new \Exception("Failed to open file {$fileid}");
                }
                $response = new StreamResponse($handle);
            }

            $response->addHeader('Content-Type', $file->getMimeType());

            $meta = $this->getIndexMeta($fileid);
            $metadata = [
                'etag' => $file->getEtag(),
                'mtime' => $meta['mtime'],
                'parent_id' => $meta['parent_id'],
                'mimetype' => $file->getMimeType(),
                'epoch' => $meta['epoch'],
                'dayid' => $meta['dayid'],
                'places' => $this->getLensPlaces($fileid),
            ];
            $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (\is_string($json)) {
                $response->addHeader('X-Memories-Metadata', base64_encode($json));
            }

            return $response;
        });
    }

    /**
     * Allocate the next catalog batch for daemon reconciliation.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function scan(): Http\Response
    {
        return $this->util->guardEx(function () {
            $this->serviceManager->guardLensServiceAccount();

            try {
                return new DataResponse($this->lensFolders->nextScanBatch());
            } catch (LockedException) {
                return new DataResponse([
                    'message' => 'Lens scan is busy',
                ], Http::STATUS_SERVICE_UNAVAILABLE, ['Retry-After' => '5']);
            }
        });
    }

    /**
     * Text search over the requesting user's own timeline via the Lens daemon.
     *
     * @param string $text  query text, must not be blank
     * @param int    $limit max hits, clamped to 1–500
     * @param string $mode  "visual" (default) or "ocr" for words inside the image
     */
    #[NoAdminRequired]
    public function search(string $text = '', int $limit = 50, string $mode = 'visual'): Http\Response
    {
        return $this->util->guardEx(function () use ($text, $limit, $mode) {
            if ('' === trim($text)) {
                throw new HttpResponseException(new DataResponse([
                    'message' => 'Search text must not be empty',
                ], Http::STATUS_BAD_REQUEST));
            }

            $folders = $this->userFolders();
            if ([] === $folders) {
                return new DataResponse([]);
            }

            $body = $this->daemonPost('ocr' === $mode ? '/v1/search/ocr' : '/v1/search', [
                'text' => $text,
                'folders' => $folders,
                'limit' => min(500, max(1, $limit)),
            ]);

            return new DataResponse($this->mapHits($body['hits'] ?? []));
        });
    }

    /**
     * Files that look like the given one, within the user's timeline.
     *
     * @param int $fileid reference file, must be visible to the user
     * @param int $limit  max hits, clamped to 1–500
     */
    #[NoAdminRequired]
    public function similar(int $fileid, int $limit = 100): Http\Response
    {
        return $this->util->guardEx(function () use ($fileid, $limit) {
            $this->fs->getUserFile($fileid);

            $folders = $this->userFolders();
            if ([] === $folders) {
                return new DataResponse([]);
            }

            $body = $this->daemonPost('/v1/similar', [
                'fileid' => $fileid,
                'folders' => $folders,
                'limit' => min(500, max(1, $limit)),
            ], 404);

            return new DataResponse($this->mapHits($body['hits'] ?? []));
        });
    }

    /**
     * Groups of near-identical files in the user's timeline, with sizes for picking the keeper.
     *
     * @param bool $refresh bypass the daemon's short-lived cache
     */
    #[NoAdminRequired]
    public function duplicates(bool $refresh = false): Http\Response
    {
        return $this->util->guardEx(function () use ($refresh) {
            $folders = $this->userFolders();
            if ([] === $folders) {
                return new DataResponse(['groups' => []]);
            }

            $body = $this->daemonPost('/v1/duplicates', [
                'folders' => $folders,
                'refresh' => $refresh,
            ], null, 120);

            $groups = array_values(array_filter(array_map(
                fn ($g) => \is_array($g) ? $this->mapHits($g) : [],
                \is_array($body['groups'] ?? null) ? $body['groups'] : [],
            ), static fn ($g) => \count($g) > 1));

            // Sizes and names come from the filecache, not the daemon.
            $ids = array_merge(...array_map(static fn ($g) => array_column($g, 'fileid'), $groups ?: [[]]));
            $info = $this->fileInfo($ids);

            foreach ($groups as &$group) {
                foreach ($group as &$hit) {
                    $hit['size'] = $info[$hit['fileid']]['size'] ?? null;
                    $hit['basename'] = $info[$hit['fileid']]['name'] ?? null;
                }
                unset($hit);

                // Keeper first: most pixels, then largest file, then oldest id.
                usort($group, static fn ($a, $b) => [($b['w'] ?? 0) * ($b['h'] ?? 0), $b['size'] ?? 0, $a['fileid']]
                    <=> [($a['w'] ?? 0) * ($a['h'] ?? 0), $a['size'] ?? 0, $b['fileid']]);
            }
            unset($group);

            return new DataResponse([
                'groups' => $groups,
                'threshold' => $body['threshold'] ?? null,
            ]);
        });
    }

    /**
     * Folder IDs covering the user's timeline, derived server-side, never trusted from the client.
     *
     * @return list<int>
     */
    private function userFolders(): array
    {
        $root = new TimelineRoot();
        $this->fs->populateRoot($root, true);

        if ($root->isEmpty()) {
            return [];
        }

        return $this->lensFolders->getFolderIds($root->getIds());
    }

    /**
     * POST JSON to the daemon; maps transport failures to 503 and $passStatus to itself.
     *
     * @return array<string, mixed>
     */
    private function daemonPost(string $path, array $json, ?int $passStatus = null, int $timeout = 10): array
    {
        $base = $this->lens->daemonUrl();
        if ('' === $base) {
            throw new HttpResponseException(new DataResponse([
                'message' => 'Lens daemon not configured',
            ], Http::STATUS_SERVICE_UNAVAILABLE));
        }

        try {
            $res = $this->clientService->newClient()->post($base.$path, [
                'json' => $json,
                'timeout' => $timeout,
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Exception $e) {
            $code = method_exists($e, 'getResponse') && $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            if (null !== $passStatus && $code === $passStatus) {
                throw new HttpResponseException(new DataResponse([
                    'message' => 'Not indexed yet',
                ], $passStatus));
            }

            throw new HttpResponseException(new DataResponse([
                'message' => 'Lens daemon unreachable',
            ], Http::STATUS_SERVICE_UNAVAILABLE));
        }

        $body = json_decode((string) $res->getBody(), true);

        return \is_array($body) ? $body : [];
    }

    /**
     * Daemon hits to the public shape; missing keys map to null, never fatal on old points.
     *
     * @return list<array<string, mixed>>
     */
    private function mapHits(mixed $hits): array
    {
        if (!\is_array($hits)) {
            return [];
        }

        return array_values(array_map(static fn ($hit) => [
            'fileid' => (int) ($hit['fileid'] ?? 0),
            'score' => $hit['score'] ?? null,
            'w' => isset($hit['w']) ? (int) $hit['w'] : null,
            'h' => isset($hit['h']) ? (int) $hit['h'] : null,
            'etag' => $hit['etag'] ?? null,
            'mimetype' => $hit['mimetype'] ?? null,
            'epoch' => isset($hit['epoch']) ? (int) $hit['epoch'] : null,
            'dayid' => isset($hit['dayid']) ? (int) $hit['dayid'] : null,
        ], array_filter($hits, '\is_array')));
    }

    /**
     * Name and size per fileid from the filecache.
     *
     * @param int[] $ids
     *
     * @return array<int, array{name: string, size: int}>
     */
    private function fileInfo(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
            $qb = $this->connection->getQueryBuilder();
            $qb->select('fileid', 'name', 'size')
                ->from('filecache')
                ->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
            ;
            foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
                $out[(int) $row['fileid']] = ['name' => (string) $row['name'], 'size' => (int) $row['size']];
            }
        }

        return $out;
    }

    /**
     * Catalog revision/dates and current storage parent, independent of the service user's mounts.
     *
     * Best-effort: failures never break file serving.
     *
     * @return array{epoch: ?int, dayid: ?int, parent_id: ?int, mtime: ?int}
     */
    private function getIndexMeta(int $fileid): array
    {
        try {
            $qb = $this->connection->getQueryBuilder();
            $qb->select('m.epoch', 'm.dayid', 'm.mtime', 'f.parent')
                ->from('filecache', 'f')
                ->leftJoin('f', 'memories', 'm', $qb->expr()->eq('f.fileid', 'm.fileid'))
                ->where($qb->expr()->eq('f.fileid', $qb->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)))
            ;
            $row = $qb->executeQuery()->fetchAssociative();
            if (false !== $row) {
                return [
                    'epoch' => isset($row['epoch']) ? (int) $row['epoch'] : null,
                    'dayid' => isset($row['dayid']) ? (int) $row['dayid'] : null,
                    'parent_id' => (int) $row['parent'],
                    'mtime' => isset($row['mtime']) ? (int) $row['mtime'] : null,
                ];
            }
        } catch (\Throwable) {
        }

        return ['epoch' => null, 'dayid' => null, 'parent_id' => null, 'mtime' => null];
    }

    /**
     * Individual places of a file for the daemon, leaf first.
     *
     * Best-effort: failures never break file serving.
     *
     * @return list<array{osm_id: int, admin_level: int, name: string}>
     */
    private function getLensPlaces(int $fileid): array
    {
        try {
            return $this->tq->getPlacesById($fileid);
        } catch (\Throwable) {
            return [];
        }
    }
}
