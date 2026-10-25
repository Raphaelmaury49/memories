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
use OCA\Memories\Util;
use OCP\Config\IUserConfig;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ISession;

/**
 * Per-user hidden folder kept out of the timeline, search and people,
 * viewable in Memories only after unlocking with a PIN for a short session.
 *
 * This hides; it does not encrypt. Files stay readable from the Files app
 * when hidden files are shown there.
 */
final class LockedFolder
{
    public const string DIR = '.locked';

    /** Unlock lifetime in seconds, renewed on each locked-view request. */
    public const int TTL = 600;

    private const string SESSION_KEY = 'memories_locked_until';
    private const string PIN_KEY = 'lockedPin';

    public function __construct(
        private IUserConfig $userConfig,
        private IRootFolder $rootFolder,
        private ISession $session,
        private Util $util,
    ) {}

    public static function path(): string
    {
        return '/'.self::DIR;
    }

    public function hasPin(?string $uid = null): bool
    {
        return '' !== $this->userConfig->getValueString($uid ?? $this->util->getUID(), Application::APPNAME, self::PIN_KEY);
    }

    /**
     * Set the PIN; an existing PIN must be presented to change it.
     *
     * @throws \InvalidArgumentException bad format or wrong current PIN
     */
    public function setPin(string $pin, ?string $current): void
    {
        if (!preg_match('/^\d{4,12}$/', $pin)) {
            throw new \InvalidArgumentException('PIN must be 4 to 12 digits');
        }

        if ($this->hasPin() && (null === $current || !$this->check($current))) {
            throw new \InvalidArgumentException('Current PIN is incorrect');
        }

        $this->userConfig->setValueString(
            $this->util->getUID(),
            Application::APPNAME,
            self::PIN_KEY,
            password_hash($pin, PASSWORD_DEFAULT),
            flags: IUserConfig::FLAG_SENSITIVE,
        );
        $this->ensureFolder();
    }

    /**
     * Admin recovery for a forgotten PIN; the folder and its files are kept.
     */
    public function resetPin(string $uid): void
    {
        $this->userConfig->deleteUserConfig($uid, Application::APPNAME, self::PIN_KEY);
    }

    /**
     * Unlock for TTL seconds if the PIN matches.
     */
    public function unlock(string $pin): bool
    {
        if (!$this->check($pin)) {
            return false;
        }

        $this->ensureFolder();
        $this->session->set(self::SESSION_KEY, time() + self::TTL);

        return true;
    }

    public function lock(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }

    public function isUnlocked(): bool
    {
        $until = (int) $this->session->get(self::SESSION_KEY);
        if ($until <= time()) {
            return false;
        }

        $this->session->set(self::SESSION_KEY, time() + self::TTL);

        return true;
    }

    /**
     * The user's locked folder, created on first use.
     */
    public function ensureFolder(): Folder
    {
        $userFolder = $this->rootFolder->getUserFolder($this->util->getUID());

        if ($userFolder->nodeExists(self::DIR)) {
            $node = $userFolder->get(self::DIR);
            if (!$node instanceof Folder) {
                throw new \Exception(self::DIR.' exists and is not a folder');
            }

            return $node;
        }

        return $userFolder->newFolder(self::DIR);
    }

    private function check(string $pin): bool
    {
        $hash = $this->userConfig->getValueString($this->util->getUID(), Application::APPNAME, self::PIN_KEY);

        return '' !== $hash && password_verify($pin, $hash);
    }
}
