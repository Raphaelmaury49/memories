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
use OCA\Memories\Service\Partners;
use OCA\Memories\Util;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class PartnersController extends ApiController
{
    public function __construct(
        IRequest $request,
        protected Partners $partners,
        protected Util $util,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    #[NoAdminRequired]
    public function list(): Http\Response
    {
        return $this->util->guardEx(fn () => new JSONResponse($this->partners->list()));
    }

    /**
     * Start sharing my timeline with a user.
     */
    #[NoAdminRequired]
    public function add(string $partner): Http\Response
    {
        return $this->util->guardEx(function () use ($partner) {
            try {
                $this->partners->add($partner);
            } catch (\InvalidArgumentException $e) {
                throw Exceptions::BadRequest($e->getMessage());
            }

            return new JSONResponse($this->partners->list());
        });
    }

    #[NoAdminRequired]
    public function remove(string $partner): Http\Response
    {
        return $this->util->guardEx(function () use ($partner) {
            $this->partners->remove($partner);

            return new JSONResponse($this->partners->list());
        });
    }

    /**
     * Show or hide a partner's photos in my timeline.
     */
    #[NoAdminRequired]
    public function setInTimeline(string $owner, bool $show): Http\Response
    {
        return $this->util->guardEx(function () use ($owner, $show) {
            $this->partners->setInTimeline($owner, $show);

            return new JSONResponse($this->partners->list());
        });
    }
}
