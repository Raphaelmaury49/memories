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

namespace OCA\Memories\Controller;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Exceptions;
use OCA\Memories\Service\FaceMerge;
use OCA\Memories\Service\People;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class PeopleController extends ApiController
{
    public function __construct(
        IRequest $request,
        protected People $people,
        protected FaceMerge $faceMerge,
        protected SystemConfig $systemConfig,
        protected Util $util,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    /**
     * Metadata of one person of the current user.
     *
     * @param string $backend recognize or facerecognition
     * @param string $name    name or id as used in the people routes
     */
    #[NoAdminRequired]
    public function meta(string $backend, string $name): Http\Response
    {
        return $this->util->guardEx(function () use ($backend, $name) {
            $id = $this->resolve($backend, $name);

            return new JSONResponse([
                'cluster_id' => $id,
                'birthdate' => $this->people->birthdates($backend, [$id])[$id] ?? null,
            ]);
        });
    }

    /**
     * Set or clear (empty string) a person's birthdate.
     *
     * @param string $birthdate YYYY-MM-DD or empty
     */
    #[NoAdminRequired]
    public function setBirthdate(string $backend, string $name, string $birthdate = ''): Http\Response
    {
        return $this->util->guardEx(function () use ($backend, $name, $birthdate) {
            $id = $this->resolve($backend, $name);

            try {
                $this->people->setBirthdate($backend, $id, '' === $birthdate ? null : $birthdate);
            } catch (\InvalidArgumentException $e) {
                throw Exceptions::BadRequest($e->getMessage());
            }

            return new JSONResponse(['cluster_id' => $id, 'birthdate' => '' === $birthdate ? null : $birthdate]);
        });
    }

    private function resolve(string $backend, string $name): string
    {
        try {
            $id = $this->people->canonicalId($backend, $name);
        } catch (\InvalidArgumentException $e) {
            throw Exceptions::BadRequest($e->getMessage());
        }

        if (null === $id) {
            throw Exceptions::NotFound("person {$name}");
        }

        return $id;
    }

    /**
     * Recognize people of the current user that look like the same person.
     */
    #[NoAdminRequired]
    public function mergeSuggestions(int $limit = 20): Http\Response
    {
        return $this->util->guardEx(function () use ($limit) {
            $this->requireRecognize();

            return new JSONResponse([
                'threshold' => $this->faceMerge->threshold(),
                'pairs' => $this->faceMerge->suggestions($this->util->getUID(), null, max(1, min($limit, 100))),
            ]);
        });
    }

    /**
     * Move every face of one Recognize person into another.
     */
    #[NoAdminRequired]
    public function merge(int $from, int $to): Http\Response
    {
        return $this->util->guardEx(function () use ($from, $to) {
            $this->requireRecognize();

            try {
                $this->faceMerge->merge($this->util->getUID(), $from, $to);
            } catch (\InvalidArgumentException $e) {
                throw Exceptions::BadRequest($e->getMessage());
            }

            return new JSONResponse(['cluster_id' => $to]);
        });
    }

    /**
     * Hide a suggestion for good: the two people are different.
     */
    #[NoAdminRequired]
    public function mergeDismiss(int $a, int $b): Http\Response
    {
        return $this->util->guardEx(function () use ($a, $b) {
            $this->requireRecognize();
            $this->faceMerge->dismiss($this->util->getUID(), $a, $b);

            return new JSONResponse([]);
        });
    }

    private function requireRecognize(): void
    {
        if (!$this->systemConfig->recognizeIsEnabled()) {
            throw Exceptions::NotEnabled('Recognize');
        }
    }
}
