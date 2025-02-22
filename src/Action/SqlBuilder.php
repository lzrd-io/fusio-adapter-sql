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

namespace Fusio\Adapter\Sql\Action;

use Doctrine\DBAL\Connection;
use Fusio\Engine\ActionAbstract;
use Fusio\Engine\ContextInterface;
use Fusio\Engine\Exception\ConfigurationException;
use Fusio\Engine\Form\BuilderInterface;
use Fusio\Engine\Form\ElementFactoryInterface;
use Fusio\Engine\ParametersInterface;
use Fusio\Engine\Request\HttpRequest;
use Fusio\Engine\Request\HttpRequestContext;
use Fusio\Engine\Request\RpcRequest;
use Fusio\Engine\Request\RpcRequestContext;
use Fusio\Engine\RequestInterface;
use PSX\Http\Environment\HttpResponseInterface;
use PSX\Http\Exception as StatusCode;
use PSX\Nested\Builder;
use PSX\Nested\JsonProvider;

/**
 * SqlBuilder
 *
 * @author  Christoph Kappestein <christoph.kappestein@gmail.com>
 * @license http://www.gnu.org/licenses/agpl-3.0
 * @link    https://www.fusio-project.org/
 */
class SqlBuilder extends ActionAbstract
{
    public function getName(): string
    {
        return 'SQL-Builder';
    }

    public function handle(RequestInterface $request, ParametersInterface $configuration, ContextInterface $context): HttpResponseInterface
    {
        $connection = $this->getConnection($configuration);

        $definition = (new JsonProvider($connection))->create(json_decode($configuration->get('jql')), $this->getContext($request));
        $data = (new Builder($connection))->build($definition);

        if (empty($data)) {
            throw new StatusCode\NotFoundException('Entry not available');
        }

        return $this->response->build(200, [], $data);
    }

    public function configure(BuilderInterface $builder, ElementFactoryInterface $elementFactory): void
    {
        $builder->add($elementFactory->newConnection('connection', 'Connection', 'The SQL connection which should be used'));
        $builder->add($elementFactory->newTextArea('jql', 'JQL', 'json', 'The JQL to query the database'));
    }

    private function getContext(RequestInterface $request): array
    {
        $requestContext = $request->getContext();
        if ($requestContext instanceof HttpRequestContext) {
            return array_merge($requestContext->getParameters(), $requestContext->getRequest()->getUri()->getParameters());
        } elseif ($requestContext instanceof RpcRequestContext) {
            return [];
        } else {
            return [];
        }
    }

    private function getConnection(ParametersInterface $configuration): Connection
    {
        $connection = $this->connector->getConnection($configuration->get('connection'));
        if (!$connection instanceof Connection) {
            throw new ConfigurationException('Given connection must be a DBAL connection');
        }

        return $connection;
    }
}
