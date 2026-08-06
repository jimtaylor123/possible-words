<?php

declare(strict_types=1);

namespace RichanFongdasen\Turso\Database;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;

class TursoConnector extends Connector implements ConnectorInterface
{
    /**
     * Establish a database connection.
     *
     * @return \RichanFongdasen\Turso\Database\TursoPDO
     */
    public function connect(array $config)
    {
        $options = $this->getOptions($config);

        if (isset($config['db_url']) && str_starts_with($config['db_url'], 'libsql:')) {
            $config['db_url'] = str_replace('libsql:', 'https:', $config['db_url']);
        }

        return new TursoPDO($config, $options);
    }
}
