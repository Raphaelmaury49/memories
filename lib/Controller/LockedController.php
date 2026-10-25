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
use OCA\Memories\Service\LockedFolder;
use OCA\Memories\Util;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class LockedController extends ApiController
{
    public function __construct(
        IRequest $request,
        protected LockedFolder $locked,
        protected Util $util,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    #[NoAdminRequired]
    public function status(): Http\Response
    {
        return $this->util->guardEx(fn () => new JSONResponse([
            'has_pin' => $this->locked->hasPin(),
            'unlocked' => $this->locked->isUnlocked(),
            'path' => LockedFolder::path(),
            'ttl' => LockedFolder::TTL,
        ]));
    }

    /**
     * Create or change the PIN.
     *
     * @param string $pin     new PIN, 4–12 digits
     * @param string $current current PIN when one is already set
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 5, period: 300)]
    #[BruteForceProtection(action: 'memories_locked')]
    public function setPin(string $pin, string $current = ''): Http\Response
    {
        return $this->util->guardEx(function () use ($pin, $current) {
            try {
                $this->locked->setPin($pin, '' === $current ? null : $current);
            } catch (\InvalidArgumentException $e) {
                $res = new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
                $res->throttle(['action' => 'memories_locked']);

                return $res;
            }

            $this->locked->unlock($pin);

            return new JSONResponse(['unlocked' => true]);
        });
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 300)]
    #[BruteForceProtection(action: 'memories_locked')]
    public function unlock(string $pin): Http\Response
    {
        return $this->util->guardEx(function () use ($pin) {
            if (!$this->locked->hasPin()) {
                throw Exceptions::BadRequest('No PIN set');
            }

            if (!$this->locked->unlock($pin)) {
                $res = new JSONResponse(['message' => 'Wrong PIN'], Http::STATUS_FORBIDDEN);
                $res->throttle(['action' => 'memories_locked']);

                return $res;
            }

            return new JSONResponse(['unlocked' => true, 'ttl' => LockedFolder::TTL]);
        });
    }

    #[NoAdminRequired]
    public function lock(): Http\Response
    {
        return $this->util->guardEx(function () {
            $this->locked->lock();

            return new JSONResponse(['unlocked' => false]);
        });
    }
}
