<?php
/*
 * Fusio
 * A web-application to create dynamically RESTful APIs
 *
 * Copyright (C) 2015-2023 Christoph Kappestein <christoph.kappestein@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Fusio\Adapter\Sql\Generator;

use PSX\Schema\Document\Document;
use PSX\Schema\Document\Type;

/**
 * JqlBuilder
 *
 * @author  Christoph Kappestein <christoph.kappestein@gmail.com>
 * @license http://www.gnu.org/licenses/agpl-3.0
 * @link    https://www.fusio-project.org/
 */
class JqlBuilder
{
    public function getCollection(Type $type, array $tableNames, Document $document): string
    {
        $tableName = $tableNames[$type->getName() ?? ''] ?? '';
        $columns = [];
        $definition = $this->getDefinition($type, $tableNames, $document, $columns);

        $jql = [
            'totalResults' => [
                '$value' => 'SELECT COUNT(*) AS cnt FROM ' . $tableName,
                '$definition' => [
                    '$key' => 'cnt',
                    '$field' => 'integer',
                ],
            ],
            'startIndex' => [
                '$context' => 'startIndex',
                '$default' => 0
            ],
            'itemsPerPage' => 16,
            'entry' => [
                '$collection' => 'SELECT ' . implode(', ', $columns) . ' FROM ' . $tableName . ' ORDER BY id DESC',
                '$offset' => [
                    '$context' => 'startIndex',
                    '$default' => 0
                ],
                '$limit' => 16,
                '$definition' => $definition
            ]
        ];

        return \json_encode($jql, JSON_PRETTY_PRINT);
    }

    public function getEntity(Type $type, array $tableNames, Document $document): string
    {
        $tableName = $tableNames[$type->getName() ?? ''] ?? '';
        $columns = [];
        $definition = $this->getDefinition($type, $tableNames, $document, $columns);

        $jql = [
            '$entity' => 'SELECT ' . implode(', ', $columns) . ' FROM ' . $tableName . ' WHERE id = :id',
            '$params' => [
                'id' => [
                    '$context' => 'id'
                ]
            ],
            '$definition' => $definition
        ];

        return \json_encode($jql, JSON_PRETTY_PRINT);
    }

    private function getDefinition(Type $type, array $tableNames, Document $document, array &$columns, int $depth = 0): array
    {
        $definition = [];

        $columns[] = 'id';
        $definition['id'] = [
            '$field' => 'integer',
        ];

        foreach ($type->getProperties() as $property) {
            $value = null;
            if (EntityExecutor::isScalar($property->getType() ?? '')) {
                $columnName = EntityExecutor::getColumnName($property);
                $columns[] = $columnName;

                $value = [
                    '$key' => $columnName,
                    '$field' => $property->getType(),
                ];
            } elseif ($property->getType() === 'object') {
                if ($depth > 0) {
                    continue;
                }

                $index = $document->indexOf($property->getFirstRef() ?? '');
                if ($index === null) {
                    continue;
                }

                $columnName = EntityExecutor::getColumnName($property);
                $columns[] = $columnName;

                $foreignType = $document->getType($index);
                if ($foreignType === null) {
                    continue;
                }

                $foreignTable = $tableNames[$property->getFirstRef() ?? ''];

                $foreignColumns = [];
                $entityDefinition = $this->getDefinition($foreignType, $tableNames, $document, $foreignColumns, $depth + 1);

                foreach ($foreignColumns as $index => $column) {
                    $foreignColumns[$index] = 'entity.' . $column;
                }

                $value = [
                    '$entity' => 'SELECT ' . implode(', ', $foreignColumns) . ' FROM ' . $foreignTable . ' entity WHERE entity.id = :id',
                    '$params' => [
                        'id' => [
                            '$ref' => EntityExecutor::getColumnName($property),
                        ],
                    ],
                    '$definition' => $entityDefinition,
                ];
            } elseif ($property->getType() === 'map') {
                if (EntityExecutor::isScalar($property->getFirstRef() ?? '')) {
                    $columnName = EntityExecutor::getColumnName($property);
                    $columns[] = $columnName;

                    $value = [
                        '$key' => $columnName,
                        '$field' => 'json',
                    ];
                } else {
                    if ($depth > 0) {
                        continue;
                    }

                    $index = $document->indexOf($property->getFirstRef() ?? '');
                    if ($index === null) {
                        continue;
                    }

                    $foreignType = $document->getType($index);
                    if ($foreignType === null) {
                        continue;
                    }

                    $table = $tableNames[$type->getName() ?? ''];
                    $foreignTable = $tableNames[$property->getFirstRef() ?? ''];
                    $relationTable = $table . '_' . EntityExecutor::underscore($property->getFirstRef() ?? '');

                    $foreignColumns = [];
                    $mapDefinition = $this->getDefinition($foreignType, $tableNames, $document, $foreignColumns, $depth + 1);

                    foreach ($foreignColumns as $index => $column) {
                        $foreignColumns[$index] = 'entity.' . $column;
                    }

                    array_unshift($foreignColumns, 'rel.name AS hash_key');

                    $query = 'SELECT ' . implode(', ', $foreignColumns) . ' ';
                    $query.= 'FROM ' . $relationTable . ' rel ';
                    $query.= 'INNER JOIN ' . $foreignTable . ' entity ';
                    $query.= 'ON entity.id = rel.' . EntityExecutor::underscore($property->getFirstRef() ?? '') . '_id ';
                    $query.= 'WHERE rel.' . EntityExecutor::underscore($type->getName() ?? '') . '_id = :id ';
                    $query.= 'ORDER BY entity.id DESC ';
                    $query.= 'LIMIT 16';

                    $value = [
                        '$collection' => $query,
                        '$params' => [
                            'id' => [
                                '$ref' => 'id',
                            ],
                        ],
                        '$definition' => $mapDefinition,
                        '$key' => 'hash_key'
                    ];
                }
            } elseif ($property->getType() === 'array') {
                if (EntityExecutor::isScalar($property->getFirstRef() ?? '')) {
                    $columns[] = EntityExecutor::getColumnName($property);

                    $value = [
                        '$field' => 'json',
                    ];
                } else {
                    if ($depth > 0) {
                        continue;
                    }

                    $index = $document->indexOf($property->getFirstRef() ?? '');
                    if ($index === null) {
                        continue;
                    }

                    $foreignType = $document->getType($index);
                    if ($foreignType === null) {
                        continue;
                    }

                    $table = $tableNames[$type->getName() ?? ''];
                    $foreignTable = $tableNames[$property->getFirstRef() ?? ''];
                    $relationTable = $table . '_' . EntityExecutor::underscore($property->getFirstRef() ?? '');

                    $foreignColumns = [];
                    $arrayDefinition = $this->getDefinition($foreignType, $tableNames, $document, $foreignColumns, $depth + 1);

                    foreach ($foreignColumns as $index => $column) {
                        $foreignColumns[$index] = 'entity.' . $column;
                    }

                    $query = 'SELECT ' . implode(', ', $foreignColumns) . ' ';
                    $query.= 'FROM ' . $relationTable . ' rel ';
                    $query.= 'INNER JOIN ' . $foreignTable . ' entity ';
                    $query.= 'ON entity.id = rel.' . EntityExecutor::underscore($property->getFirstRef() ?? '') . '_id ';
                    $query.= 'WHERE rel.' . EntityExecutor::underscore($type->getName() ?? '') . '_id = :id ';
                    $query.= 'ORDER BY entity.id DESC ';
                    $query.= 'LIMIT 16';

                    $value = [
                        '$collection' => $query,
                        '$params' => [
                            'id' => [
                                '$ref' => 'id',
                            ],
                        ],
                        '$definition' => $arrayDefinition,
                    ];
                }
            }

            if ($value !== null) {
                $definition[$property->getName() ?? ''] = $value;
            }
        }

        return $definition;
    }
}
