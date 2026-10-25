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
use OCA\Memories\Service\People;
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
}
