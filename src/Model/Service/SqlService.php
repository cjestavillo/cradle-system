<?php //-->
/**
 * This file is part of a package designed for the CradlePHP Project.
 *
 * Copyright and license information can be found at LICENSE.txt
 * distributed with this package.
 */

namespace Cradle\Package\System\Model\Service;

use PDO as Resource;
use Cradle\Storm\SqlFactory;

use Cradle\Module\Utility\Service\SqlServiceInterface;
use Cradle\Module\Utility\Service\AbstractSqlService;

use Cradle\Package\System\Schema;
use Cradle\Package\System\Exception as SystemException;

/**
 * Model SQL Service
 *
 * @vendor   Cradle
 * @package  System
 * @author   Christan Blanquera <cblanquera@openovate.com>
 * @standard PSR-2
 */
class SqlService
{
    /**
     * @var AbstractSql|null $resource
     */
    protected $resource = null;

    /**
     * @var Schema|null $schema
     */
    protected $schema = null;

    /**
     * Registers the resource for use
     *
     * @param Resource $resource
     */
    public function __construct(Resource $resource)
    {
        $this->resource = SqlFactory::load($resource);
    }

    /**
     * Create in database
     *
     * @param *array $object
     * @param *array $data
     *
     * @return array
     */
    public function create(array $data)
    {
        if (is_null($this->schema)) {
            throw SystemException::forNoSchema();
        }

        $table = $this->schema->getName();
        $created = $this->schema->getCreatedFieldName();
        $updated = $this->schema->getUpdatedFieldName();

        if ($created) {
            $data[$created] = date('Y-m-d H:i:s');
        }

        if ($updated) {
            $data[$updated] = date('Y-m-d H:i:s');
        }

        $uuids = $this->schema->getUuidFieldNames();

        foreach($uuids as $uuid) {
            $data[$uuid] = sha1(uniqid());
        }

        return $this
            ->resource
            ->model($data)
            ->save($table)
            ->get();
    }

    /**
     * Checks to see if unique.0 already exists
     *
     * @param *string $objectKey
     *
     * @return bool
     */
    public function exists($key, $value)
    {
        if (is_null($this->schema)) {
            throw SystemException::forNoSchema();
        }

        $search = $this
            ->resource
            ->search($this->schema->getName())
            ->addFilter($key . ' = %s', $value);

        return !!$search->getRow();
    }

    /**
     * Get detail from database
     *
     * @param *array $object
     * @param *int   $id
     *
     * @return array
     */
    public function get($key, $id)
    {
        if (is_null($this->schema)) {
            throw SystemException::forNoSchema();
        }

        $search = $this
            ->resource
            ->search($this->schema->getName())
            ->addFilter($key . ' = %s', $id);

        //get 1:1 relations
        $relations = $this->schema->getRelations(1);

        foreach ($relations as $table => $relation) {
            $search
                ->innerJoinUsing(
                    $table,
                    $relation['primary1']
                )
                ->innerJoinUsing(
                    $relation['name'],
                    $relation['primary2']
                );
        }

        $results = $search->getRow();

        if (!$results) {
            return $results;
        }

        $fields = $this->schema->getJsonFieldNames();

        foreach ($fields as $field) {
            if (isset($results[$field]) && $results[$field]) {
                $results[$field] = json_decode($results[$field], true);
            } else {
                $results[$field] = [];
            }
        }

        //get 1:0 relations
        $relations = $this->schema->getRelations(0);
        foreach ($relations as $table => $relation) {
            $results[$relation['name']] = $this
                ->resource
                ->search($table)
                ->innerJoinUsing($relation['name'], $relation['primary2'])
                ->addFilter($relation['primary1'] . ' = %s', $id)
                ->getRow();
        }

        //get 1:N, N:N relations
        $relations = array_merge(
            $this->schema->getRelations(2),
            $this->schema->getRelations(3)
        );

        foreach ($relations as $table => $relation) {
            $schema = $this->schema;
            $results[$relation['name']] = $this
                ->resource
                ->search($table)
                ->when(
                    //we need to case for post_post for example
                    $relation['name'] === $this->schema->getName(),
                    //this is the post_post way
                    function () use (&$schema, &$relation) {
                        $on = sprintf(
                            '%s = %s',
                            $schema->getPrimaryFieldName(),
                            $relation['primary2']
                        );
                        $this->innerJoinOn($relation['name'], $on);
                    },
                    //this is the normal way
                    function () use (&$relation) {
                        $this->innerJoinUsing($relation['name'], $relation['primary2']);
                    }
                )
                ->addFilter($relation['primary1'] . ' = %s', $id)
                ->getRows();
        }

        return $results;
    }

    /**
     * Returns the SQL resource
     *
     * @return Resource
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * Links table to another table
     *
     * @param *string $relation
     * @param *int    $primary1
     * @param *int    $primary2
     *
     * @return array
     */
    public function link($relation, $primary1, $primary2)
    {
        if (is_null($this->schema)) {
            throw SystemException::forNoSchema();
        }

        $name = $this->schema->getName();
        $relations = $this->schema->getRelations();
        $table = $name . '_' . $relation;

        if (!isset($relations[$table])) {
            throw SystemException::forNoRelation($name, $relation);
        }

        $relation = $relations[$table];

        $model = $this->resource->model();
        $model[$relation['primary1']] = $primary1;
        $model[$relation['primary2']] = $primary2;

        return $model->insert($table);
    }

    /**
     * Remove from database
     * PLEASE BECAREFUL USING THIS !!!
     * It's here for clean up scripts
     *
     * @param *array $object
     * @param *int $id
     */
    public function remove($id)
    {
        if (is_null($this->schema)) {
            throw SystemException::forNoSchema();
        }

        $table = $this->schema->getName();
        $primary = $this->schema->getPrimaryFieldName();
        //please rely on SQL CASCADING ON DELETE
        $model = $this->resource->model();
        $model[$primary] = $id;
        return $model->remove($table);
    }

    /**
     * Search in database
     *
     * @param *array $object
     * @param array  $data
     *
     * @return array
     */
    public function search(array $data = [])
    {
        if (is_null($this->schema)) {
            throw SystemException::forNoSchema();
        }

        $filter = [];
        $span  = [];
        $range = 50;
        $start = 0;
        $order = [];
        $count = 0;

        if (isset($data['filter']) && is_array($data['filter'])) {
            $filter = $data['filter'];
        }

        if (isset($data['span']) && is_array($data['span'])) {
            $span = $data['span'];
        }

        if (isset($data['range']) && is_numeric($data['range'])) {
            $range = $data['range'];
        }

        if (isset($data['start']) && is_numeric($data['start'])) {
            $start = $data['start'];
        }

        if (isset($data['order']) && is_array($data['order'])) {
            $order = $data['order'];
        }

        $active = $this->schema->getActiveFieldName();
        if ($active && !isset($filter[$active])) {
            $filter[$active] = 1;
        }

        $search = $this->resource
            ->search($this->schema->getName())
            ->setStart($start)
            ->setRange($range);

        //get 1:1 relations
        $relations = $this->schema->getRelations(1);
        foreach ($relations as $table => $relation) {
            //deal with post_post at a later time
            if ($relation['name'] === $this->schema->getName()) {
                continue;
            }

            $search
                ->innerJoinUsing(
                    $table,
                    $relation['primary1']
                )
                ->innerJoinUsing(
                    $relation['name'],
                    $relation['primary2']
                );
        }

        //get N:N relations
        $relations = $this->schema->getRelations(3);
        foreach ($relations as $table => $relation) {
            //deal with post_post at a later time
            if ($relation['name'] === $this->schema->getName()) {
                continue;
            }

            if (!isset($filter[$relation['primary2']])) {
                continue;
            }

            $search->innerJoinUsing(
                $table,
                $relation['primary1']
            );
        }

        //get 1:N reverse relations
        $relations = $this->schema->getReverseRelations(2);
        foreach ($relations as $table => $relation) {
            //deal with post_post at a later time
            if ($relation['source']['name'] === $relation['name']) {
                continue;
            }

            //if filter primary is not set
            if (!isset($filter[$relation['primary1']])) {
                continue;
            }

            $search->innerJoinUsing(
                $table,
                $relation['primary2']
            );
        }

        //get N:N reverse relations
        $relations = $this->schema->getReverseRelations(3);
        foreach ($relations as $table => $relation) {
            //deal with post_post at a later time
            if ($relation['source']['name'] === $relation['name']) {
                continue;
            }

            //if filter primary is not set
            if (!isset($filter[$relation['primary1']])) {
                continue;
            }

            $search->innerJoinUsing(
                $table,
                $relation['primary2']
            );
        }

        //now deal with post_post
        $circular = $this->schema->getRelations($this->schema->getName());
        //if there is a post_post and they are trying to filter by it
        if (!empty($circular) && isset($filter[$circular['primary']])) {
            //they mean to filter by the parent
            $filter[$circular['primary1']] = $filter[$circular['primary']];

            //remove the old
            unset($filter[$circular['primary']]);

            //now add the join
            $search->innerJoinOn(
                $circular['table'],
                sprintf(
                    '%s = %s',
                    $circular['primary'],
                    $circular['primary2']
                )
            );
        }

        //add filters
        foreach ($filter as $column => $value) {
            if (preg_match('/^[a-zA-Z0-9-_]+$/', $column)) {
                $search->addFilter($column . ' = %s', $value);
            }
        }

        //add spans
        foreach ($span as $column => $value) {
            if (!empty($value)) {
                if (!preg_match('/^[a-zA-Z0-9-_]+$/', $column)) {
                    continue;
                }

                // minimum?
                if (isset($value[0]) && !empty($value[0])) {
                    $search
                        ->addFilter($column . ' >= %s', $value[0]);
                }

                // maximum?
                if (isset($value[1]) && !empty($value[0])) {
                    $search
                        ->addFilter($column . ' <= %s', $value[1]);
                }
            }
        }

        //keyword?
        $searchable = $this->schema->getSearchableFieldNames();

        if (!empty($searchable)) {
            $keywords = [];

            if (isset($data['q'])) {
                $keywords = $data['q'];

                if (!is_array($keywords)) {
                    $keywords = [$keywords];
                }
            }

            foreach ($keywords as $keyword) {
                $or = [];
                $where = [];
                foreach ($searchable as $name) {
                    $where[] = 'LOWER(' . $name . ') LIKE %s';
                    $or[] = '%' . strtolower($keyword) . '%';
                }

                array_unshift($or, '(' . implode(' OR ', $where) . ')');
                call_user_func([$search, 'addFilter'], ...$or);
            }
        }

        //add sorting
        foreach ($order as $sort => $direction) {
            $search->addSort($sort, $direction);
        }

        $rows = $search->getRows();
        $fields = $this->schema->getJsonFieldNames();

        foreach ($rows as $i => $results) {
            foreach ($fields as $field) {
                if (isset($results[$field]) && $results[$field]) {
                    $rows[$i][$field] = json_decode($results[$field], true);
                } else {
                    $rows[$i][$field] = [];
                }
            }
        }

        //return response format
        return [
            'rows' => $rows,
            'total' => $search->getTotal()
        ];
    }

    /**
     * Adds System Schema
     *
     * @param Schema $schema
     *
     * @return SqlService
     */
    public function setSchema(Schema $schema)
    {
        $this->schema = $schema;
        return $this;
    }

    /**
     * Unlinks table to another table
     *
     * @param *string $relation
     * @param *int    $primary1
     * @param *int    $primary2
     *
     * @return array
     */
    public function unlink($relation, $primary1, $primary2)
    {
        if (is_null($this->schema)) {
            throw SystemException::forNoSchema();
        }

        $name = $this->schema->getName();
        $relations = $this->schema->getRelations();
        $table = $name . '_' . $relation;

        if (!isset($relations[$table])) {
            throw SystemException::forNoRelation($name, $relation);
        }

        $relation = $relations[$table];

        $model = $this->resource->model();
        $model[$relation['primary1']] = $primary1;
        $model[$relation['primary2']] = $primary2;

        return $model->remove($table);
    }

    /**
     * Update to database
     *
     * @param array $data
     *
     * @return array
     */
    public function update(array $data)
    {
        if (is_null($this->schema)) {
            throw SystemException::forNoSchema();
        }

        $table = $this->schema->getName();
        $updated = $this->schema->getUpdatedFieldName();

        if ($updated) {
            $data[$updated] = date('Y-m-d H:i:s');
        }

        return $this
            ->resource
            ->model($data)
            ->save($table)
            ->get();
    }
}
