<?php

namespace Lkt\DatabaseConnectors;

use Lkt\DatabaseConnectors\Cache\QueryCache;
use Lkt\Factory\Schemas\Exceptions\InvalidComponentException;
use Lkt\Factory\Schemas\Exceptions\SchemaNotDefinedException;
use Lkt\Factory\Schemas\Fields\AbstractField;
use Lkt\Factory\Schemas\Fields\BooleanField;
use Lkt\Factory\Schemas\Fields\DateTimeField;
use Lkt\Factory\Schemas\Fields\EmailField;
use Lkt\Factory\Schemas\Fields\FileField;
use Lkt\Factory\Schemas\Fields\FloatField;
use Lkt\Factory\Schemas\Fields\ForeignKeyField;
use Lkt\Factory\Schemas\Fields\HTMLField;
use Lkt\Factory\Schemas\Fields\IntegerField;
use Lkt\Factory\Schemas\Fields\JSONField;
use Lkt\Factory\Schemas\Fields\PivotField;
use Lkt\Factory\Schemas\Fields\RelatedField;
use Lkt\Factory\Schemas\Fields\RelatedKeysField;
use Lkt\Factory\Schemas\Fields\StringField;
use Lkt\Factory\Schemas\Fields\UnixTimeStampField;
use Lkt\Factory\Schemas\Schema;
use Lkt\Locale\Locale;
use Lkt\QueryBuilding\Query;

class MySQLConnector extends DatabaseConnector
{
    protected $port = 3306;
    protected $charset = 'utf8';
    protected $rememberTotal = '';

    /**
     * @param string $rememberTotal
     * @return $this
     */
    public function setRememberTotal(string $rememberTotal): MySQLConnector
    {
        $this->rememberTotal = $rememberTotal;
        return $this;
    }

    /**
     * @return DatabaseConnector
     */
    public function connect(): DatabaseConnector
    {
        if ($this->connection !== null) {
            return $this;
        }

        // Perform the connection
        try {
            $this->connection = new \PDO (
                "mysql:host={$this->host};dbname={$this->database};charset={$this->charset}",
                $this->user,
                $this->password
            );

        } catch (\Exception $e) {
            die ('Connection to database failed');
        }
        return $this;
    }

    /**
     * @return DatabaseConnector
     */
    public function disconnect(): DatabaseConnector
    {
        $this->connection = null;
        return $this;
    }

    /**
     * @param string $query
     * @param array $replacements
     * @return array|null
     */
    public function query(string $query, array $replacements = []):? array
    {
        $this->connect();
        $sql = ConnectionHelper::sanitizeQuery($query);
        $sql = ConnectionHelper::prepareParams($sql, $replacements);
        $sql = \str_replace('_LANG', '_' . Locale::getLangCode(), $sql);
        if ($this->rememberTotal !== '') {
            $sql = \preg_replace('/SELECT/i', 'SELECT SQL_CALC_FOUND_ROWS', $sql, 1);
            $sql .= '; SET @rows_' . $this->rememberTotal . ' = FOUND_ROWS();';
        }

        $sql = trim($sql);
        $isSelect = strpos(strtolower($sql), 'select') === 0;

        // check if cached (only select queries)
        if ($isSelect && !$this->forceRefresh && !$this->ignoreCache && QueryCache::isset($this->name, $sql)) {
            return QueryCache::get($this->name, $sql)->getLatestResults();
        }

        // fetch
        $result = $this->connection->query($sql, \PDO::FETCH_ASSOC);

        if ($this->forceRefresh) {
            $this->forceRefreshFinished();
        }

        if ($result === true || $result === false) {
            QueryCache::set($this->name, $sql, null);
            return null;
        }

        $r = [];
        foreach ($result as $row) {
            $r[] = $row;
        }

        QueryCache::set($this->name, $sql, $r);
        return $r;
    }

    /**
     * @param Schema $schema
     * @return array
     * @throws InvalidComponentException
     */
    public function extractSchemaColumns(Schema $schema): array
    {
        $table = $schema->getTable();

        /** @var AbstractField[] $fields */
        $fields = $schema->getAllFields();

        $r = [];

        foreach ($fields as $key => $field) {
            if ($field instanceof PivotField || $field instanceof RelatedField || $field instanceof RelatedKeysField) {
                continue;
            }
            $column = trim($field->getColumn());
            if ($field instanceof JSONField && $field->isCompressed()) {
                $r[] = "UNCOMPRESS({$table}.{$column}) as {$key}";
            } else {
                $r[] = "{$table}.{$column} as {$key}";
            }
        }

        return $r;
    }

    private function buildColumns(Query $builder): string
    {
        $r = [];
        foreach ($builder->getColumns() as $column) {
            $r[] = $this->buildColumnString($column, $builder->getTable());
        }

        return implode(',', $r);
    }


    private function buildColumnString(string $column, string $table): string
    {
        $prependTable = "{$table}.";
        $tempColumn = str_replace([' as ', ' AS ', ' aS ', ' As '], '{{---LKT_SEPARATOR---}}', $column);
        $exploded = explode('{{---LKT_SEPARATOR---}}', $tempColumn);

        $key = trim($exploded[0]);
        $alias = isset($exploded[1]) ? trim($exploded[1]) : '';

        if (strpos($column, 'UNCOMPRESS') === 0) {
            if ($alias !== '') {
                $r = "{$key} AS {$alias}";
            } else {
                $r = $key;
            }
        }

        elseif (strpos($key, $prependTable) === 0) {
            if ($alias !== '') {
                $r = "{$key} AS {$alias}";
            } else {
                $r = $key;
            }
        } else {
            if ($alias !== '') {
                $r = "{$prependTable}{$key} AS {$alias}";
            } else {
                $r = "{$prependTable}{$key}";
            }
        }

        return $r;
    }

    /**
     * @param array $params
     * @return string
     */
    public function makeUpdateParams(array $params = []) :string
    {
        $r = [];
        foreach ($params as $field => $value) {
            $v = addslashes(stripslashes($value));
            if (strpos($value, 'COMPRESS(') === 0){
                $r[] = "`{$field}`={$value}";
            } else {
                $r[] = "`{$field}`='{$v}'";
            }
        }
        return trim(implode(',', $r));
    }

    /**
     * @return int
     */
    public function getLastInsertedId(): int
    {
        if ($this->connection === null) {
            return 0;
        }
        return (int)$this->connection->lastInsertId();
    }

    /**
     * @param Query $builder
     * @param string $type
     * @param string|null $countableField
     * @return string
     */
    public function getQuery(Query $builder, string $type, string $countableField = null): string
    {
        $whereString = $builder->getQueryWhere();

        switch ($type) {
            case 'select':
            case 'selectDistinct':
            case 'count':
                $from = [];
                foreach ($builder->getJoins() as $join) {
                    $from[] = (string)$join;
                }
                $fromString = implode(' ', $from);
                $fromString = str_replace('{{---LKT_PARENT_TABLE---}}', $builder->getTable(), $fromString);

                $distinct = '';

                if ($type === 'selectDistinct') {
                    $distinct = 'DISTINCT';
                    $type = 'select';
                }

                if ($type === 'select') {
                    $columns = $this->buildColumns($builder);
                    $orderBy = '';
                    $pagination = '';

                    if ($builder->hasOrder()) {
                        $orderBy = " ORDER BY {$builder->getOrder()}";
                    }

                    if ($builder->hasPagination()) {
                        $p = $builder->getPage() * $builder->getLimit();
                        $pagination = " LIMIT {$p}, {$builder->getLimit()}";

                    } elseif ($builder->hasLimit()) {
                        $pagination = " LIMIT {$builder->getLimit()}";
                    }


                    return "SELECT {$distinct} {$columns} FROM {$builder->getTable()} {$fromString} WHERE 1 {$whereString} {$orderBy} {$pagination}";
                }

                if ($type === 'count') {
                    return "SELECT COUNT({$countableField}) AS Count FROM {$builder->getTable()} {$fromString} WHERE 1 {$whereString}";
                }
                return '';

            case 'update':
            case 'insert':
                $data = $this->makeUpdateParams($builder->getData());

                if ($type === 'update') {
                    return "UPDATE {$builder->getTable()} SET {$data} WHERE 1 {$whereString}";
                }

                if ($type === 'insert') {
                    return "INSERT INTO {$builder->getTable()} SET {$data}";
                }
                return '';

            case 'delete':
                return "DELETE FROM {$builder->getTable()} WHERE 1 {$whereString}";

            default:
                return '';
        }
    }

    /**
     * @param Schema $schema
     * @param array $data
     * @return array
     * @throws InvalidComponentException
     * @throws SchemaNotDefinedException
     */
    public function prepareDataToStore(Schema $schema, array $data): array
    {
        $fields = $schema->getAllFields();
        $parsed = [];

        foreach ($fields as $column => $field) {
            $columnKey = $column;
            if ($field instanceof ForeignKeyField) {
                $columnKey .= 'Id';
            }
            if (array_key_exists($columnKey, $data)){
                $value = $data[$columnKey];


                $compress = $field instanceof JSONField && $field->isCompressed();

                if ($field instanceof StringField
                    || $field instanceof EmailField
                    || $field instanceof RelatedKeysField
                    || $field instanceof ForeignKeyField
                ) {
                    $r = trim($value);
                    if ($compress) {
                        $value = "COMPRESS('{$r}')";
                    } else {
                        $value = $r;
                    }
                }

                if ($field instanceof HTMLField) {
                    $r = $this->escapeDatabaseCharacters($value);
                    if ($compress) {
                        $value = "COMPRESS('{$r}')";
                    } else {
                        $value = $r;
                    }
                }

                if ($field instanceof BooleanField) {
                    $value = $value === true ? 1 : 0;
                }

                if ($field instanceof IntegerField) {
                    $value = (int)$value;
                }

                if ($field instanceof FloatField) {
                    $value = (float)$value;
                }

                if ($field instanceof UnixTimeStampField) {
                    if ($value instanceof \DateTime) {
                        $value = strtotime($value->format('Y-m-d H:i:s'));
                    } else {
                        $value = 0;
                    }
                }

                if ($field instanceof DateTimeField) {
                    if ($value instanceof \DateTime) {
                        $value = strtotime($value->format('Y-m-d H:i:s'));
                    } else {
                        $value = '0000-00-00 00:00:00';
                    }
                }

                if ($field instanceof FileField) {
                    if (is_object($value)) {
                        $value = $value->name;
                    } else {
                        $value = '';
                    }
                }

                if ($field instanceof JSONField) {
                    if (is_array($value)){
                        $v = htmlspecialchars(json_encode($value), JSON_UNESCAPED_UNICODE|ENT_QUOTES, 'UTF-8');
                        $v = $this->escapeDatabaseCharacters($v);

                        if ($compress) {
                            $value = "COMPRESS('{$v}')";
                        }
                        $value = $v;
                    }
                }
                $parsed[$field->getColumn()] = $value;
            }
        }

        return $parsed;
    }
}