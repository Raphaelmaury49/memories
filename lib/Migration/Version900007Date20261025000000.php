<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Raphaël Maury
 * @author Raphaël Maury
 * @license GNU AGPL version 3 or any later version
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

namespace OCA\Memories\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Burst grouping key on the catalog, plus per-user people metadata
 * (birthdates) and partner sharing.
 */
final class Version900007Date20261025000000 extends SimpleMigrationStep
{
    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    #[\Override]
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('memories');
        if (!$table->hasColumn('burstid')) {
            $table->addColumn('burstid', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
        }

        if (!$schema->hasTable('memories_people')) {
            $table = $schema->createTable('memories_people');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('backend', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('cluster_id', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('birthdate', Types::DATE, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['uid', 'backend', 'cluster_id'], 'memories_people_uniq');
        }

        if (!$schema->hasTable('memories_partners')) {
            $table = $schema->createTable('memories_partners');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('partner', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('share_ids', Types::STRING, ['notnull' => true, 'length' => 1024, 'default' => '']);
            $table->addColumn('in_timeline', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['owner', 'partner'], 'memories_partners_uniq');
            $table->addIndex(['partner'], 'memories_partners_partner');
        }

        return $schema;
    }
}
