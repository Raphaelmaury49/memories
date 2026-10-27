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

namespace OCA\Memories\Command;

use OCA\Memories\Service\FaceMerge;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class FacesMerge extends Command
{
    public function __construct(
        protected FaceMerge $faceMerge,
        protected IUserManager $userManager,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('memories:faces-merge')
            ->setDescription('Merge Recognize people that are the same person (split clusters)')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only this user')
            ->addOption('threshold', 't', InputOption::VALUE_REQUIRED, 'Max average face distance to merge', '0.45')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print what would be merged')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $threshold = (float) $input->getOption('threshold');
        if ($threshold <= 0 || $threshold >= 2) {
            $output->writeln('<error>Threshold must be in ]0, 2[</error>');

            return 1;
        }

        $dry = (bool) $input->getOption('dry-run');
        $uids = [];
        if ($uid = $input->getOption('user')) {
            if (!$this->userManager->userExists((string) $uid)) {
                $output->writeln("<error>User {$uid} not found</error>");

                return 1;
            }
            $uids[] = (string) $uid;
        } else {
            $this->userManager->callForSeenUsers(static function ($user) use (&$uids): void {
                $uids[] = $user->getUID();
            });
        }

        foreach ($uids as $uid) {
            $done = $this->faceMerge->autoMerge($uid, $threshold, $dry);
            foreach ($done as $m) {
                $output->writeln(\sprintf('  %s: %d -> %d (%.3f)', $uid, $m['from'], $m['to'], $m['distance']));
            }
            $output->writeln(\sprintf('%s: %d merge(s)%s', $uid, \count($done), $dry ? ' (dry run)' : ''));
        }

        return 0;
    }
}
