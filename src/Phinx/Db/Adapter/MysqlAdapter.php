<?php
/**
 * Phinx
 *
 * (The MIT license)
 * Copyright (c) 2012 Rob Morgan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated * documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 * 
 * @package    Phinx
 * @subpackage Phinx\Db\Adapter
 */
namespace Phinx\Db\Adapter;

class MysqlAdapter extends PdoAdapter implements AdapterInterface
{
    const CREATE_STATEMENT = 'CREATE TABLE `%s` ( version BIGINT(14) UNSIGNED NOT NULL, start_time timestamp NOT NULL, end_time timestamp NOT NULL );';
    
    /**
     * {@inheritdoc}
     */
    public function connect()
    {
        if (null === $this->connection) {
            if (!class_exists('PDO') || !in_array('mysql', \PDO::getAvailableDrivers(), true)) {
                // @codeCoverageIgnoreStart
                throw new \RuntimeException('You need to enable the PDO_Mysql extension for Phinx to run properly.');
                // @codeCoverageIgnoreEnd
            }
            
            $dsn = '';
            $db = null;
            $options = $this->getOptions();
            
            // if port is specified use it, otherwise use the MySQL default
            if (isset($options['port'])) {
                $dsn = 'mysql:host=' . $options['host'] . ';port=' . $options['port'] . ';dbname=' . $options['name'];
            } else {
                $dsn = 'mysql:host=' . $options['host'] . ';dbname=' . $options['name'];
            }

            try {
                $db = new \PDO($dsn, $options['user'], $options['pass'], array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION));
            } catch(\PDOException $exception) {
                throw new \InvalidArgumentException(sprintf(
                    'There was a problem connecting to the database: '
                    . $exception->getMessage()
                ));
            }

            $this->setConnection($db);
            
            // Create the schema table if it doesn't already exist
            if (!$this->hasSchemaTable()) {
                $this->createSchemaTable();
            }
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function disconnect()
    {
        $this->connection = null;
    }
    
    /**
     * {@inheritdoc}
     */
    public function quoteTableName($tableName)
    {
        return str_replace('.', '`.`', $this->quoteColumnName($tableName));
    }
    
    /**
     * {@inheritdoc}
     */
    public function quoteColumnName($columnName)
    {
        return '`' . str_replace('`', '``', $columnName) . '`';
    }
    
    /**
     * {@inheritdoc}
     */
    public function hasTable($tableName)
    {
        $options = $this->getOptions();
        
        $tables = array();
        $rows = $this->fetchAll(sprintf('SHOW TABLES IN `%s`', $options['name']));
        foreach ($rows as $row) {
            $tables[] = strtolower($row[0]);
        }
        
        return in_array(strtolower($tableName), $tables);
    }
    
    /**
     * {@inheritdoc}
     */
    public function createTable($tableName, $columns, $indexes = array(), $options = null)
    {
        // This method is based on the MySQL docs here: http://dev.mysql.com/doc/refman/5.1/en/create-index.html
        
        if (null === $options) {
            $options = array(
                'engine' => 'InnoDB'
            );
        }
        
        // Add the default primary key
        if (!isset($options['id']) || (isset($options['id']) && $options['id'] === true)) {
            array_unshift($columns, array(
                'name'    => 'id',
                'type'    => 'integer',
                'options' => array(
                    'null'           => false,
                    'auto_increment' => true
                )
            ));
            
            $options['primary_key'] = 'id';
        }
        
        // TODO - process table options like collation etc
        
        // convert options array to sql
        $optionsStr = 'ENGINE = InnoDB';
        
        $sql = 'CREATE TABLE ';
        $sql .= $this->quoteTableName($tableName) . ' (';
        foreach ($columns as $column) {
            $sqlType = $this->getSqlType($column['type']);
            $sql .= $this->quoteColumnName($column['name']) . ' ' . strtoupper($sqlType['name']);
            $sql .= (isset($column['options']['limit']) || isset($sqlType['limit']))
                  ? '(' . (isset($column['options']['limit']) ? $column['options']['limit'] : $sqlType['limit']) . ')' : '';
            $sql .= ($column['options']['null'] == false) ? ' NOT NULL' : ' NULL';
            $sql .= (isset($column['options']['auto_increment'])) ? ' AUTO_INCREMENT' : '';
            $sql .= (isset($column['options']['default'])) ? ' DEFAULT \'' . $column['options']['default'] . '\'' : '';
            $sql .= ', ';
            // TODO - add precision & scale for decimals
        }
        
        // set the primary key(s)
        if (isset($options['primary_key'])) {
            $sql = rtrim($sql);
            $sql .= ' PRIMARY KEY (';
            if (is_string($options['primary_key'])) {       // handle primary_key => 'id'
                $sql .= $this->quoteColumnName($options['primary_key']);
            } else if (is_array($options['primary_key'])) { // handle primary_key => array('tag_id', 'resource_id')
                // PHP 5.4 will allow access of $this, so we can call quoteColumnName() directly in the anonymous function,
                // but for now just hard-code the adapter quotes
                $sql .= implode(',', array_map(function($v) { return '`' . $v . '`'; }, $options['primary_key']));
            }
            $sql .= ')';
        } else {
            $sql = substr(rtrim($sql), 0, -1);              // no primary keys
        }
        
        // set the indexes
        // TODO - I don't think the following index code below supports multiple indexes.
        // i.e. more than two seperate unique indexes
        if (!empty($indexes)) {
            $sql .= ', ';
            $nIndexes = array();
            $uIndexes = array();
            foreach ($indexes as $index) {
                // FIXME - Reduce Code C.R.A.P (duplication)
                if (is_string($index['columns'])) {
                    if (isset($index['options']['unique']) && $index['options']['unique'] === true) { // unique index
                        $uIndexes[] = $this->quoteColumnName($index['columns']);
                    } else {
                        $nIndexes[] = $this->quoteColumnName($index['columns']);
                    }
                } else if (is_array($index['columns'])) {
                    if (isset($index['options']['unique']) && $index['options']['unique'] === true) { // unique index
                        $uIndexes[] = implode(',', array_map(function($v) { return '`' . $v . '`'; }, $index['columns']));
                    } else {
                        $nIndexes[] = $this->quoteColumnName($index['columns']);
                    }
                }
            }

            if (!empty($nIndexes)) {
                $sql .= ' INDEX (' . implode(',', $nIndexes) . ')';
            }
            if (!empty($uIndexes)) {
                $sql .= ' UNIQUE (' . implode(',', $uIndexes) . ')';
            }
        }
        
        $sql .= ') ' . $optionsStr;
        $sql = rtrim($sql) . ';';

        // execute the sql
        $this->execute($sql);
    }
    
    /**
     * {@inheritdoc}
     */
    public function renameTable($tableName, $newName)
    {
        $this->execute(sprintf('RENAME TABLE %s TO %s', $this->quoteTableName($tableName), $this->quoteTableName($newName)));
    }
    
    /**
     * {@inheritdoc}
     */
    public function dropTable($tableName)
    {
        $this->execute(sprintf('DROP TABLE %s', $this->quoteTableName($tableName)));
    }
    
    /**
     * {@inheritdoc}
     */
    public function hasColumn($tableName, $columnName, $options = array())
    {
        // TODO - do we need $options? I think we borrowed the signature from
        // Rails and it's meant to test indexes or something??
        $rows = $this->fetchAll(sprintf('SHOW COLUMNS FROM %s', $tableName));
        foreach ($rows as $column) {
            if (strtolower($column['Field']) == strtolower($columnName)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * {@inheritdoc}
     */
    public function addColumn($tableName, $columnName, $type, $options)
    {
        // TODO - implement
    }
    
    /**
     * {@inheritdoc}
     */
    public function renameColumn($tableName, $columnName, $newColumnName)
    {
        $rows = $this->fetchAll(sprintf('DESCRIBE %s', $this->quoteTableName($tableName)));
        foreach ($rows as $row) {
            if (strtolower($row['Field']) == strtolower($columnName)) {
                $null = ($row['Null'] == 'NO') ? 'NOT NULL' : 'NULL';
                $extra = ' ' . strtoupper($row['Extra']);
                $definition = $row['Type'] . ' ' . $null . $extra;
        
                return $this->execute(
                    sprintf('ALTER TABLE %s CHANGE COLUMN %s %s %s',
                            $this->quoteTableName($tableName),
                            $this->quoteColumnName($columnName),
                            $this->quoteColumnName($newColumnName),
                            $definition
                    )
                );
            }
        }
        
        throw new \InvalidArgumentException(sprintf(
            'The specified column doesn\'t exist: '
            . $columnName
        ));
    }
    
    /**
     * {@inheritdoc}
     */
    public function dropColumn($tableName, $columnName)
    {
        $this->execute(
            sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $this->quoteTableName($tableName),
                $this->quoteColumnName($columnName)
            )
        );
    }
    
    /**
     * {@inheritdoc}
     */
    public function hasIndex($tableName, $columns)
    {
        $options = $this->getOptions();
        $indexes = array();
        $columns = array_map('strtolower', $columns);
        
        $rows = $this->fetchAll(sprintf('SHOW INDEXES FROM %s', $this->quoteTableName($tableName)));
        foreach ($rows as $row) {
            if (!isset($indexes[$row['Key_name']])) {
                $indexes[$row['Key_name']] = array('columns' => array());
            }
            $indexes[$row['Key_name']]['columns'][] = strtolower($row['Column_name']);
        }
        
        foreach ($indexes as $index) {
            $a = array_diff($columns, $index['columns']);
            if (empty($a)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * {@inheritdoc}
     */
    public function addIndex($tableName, $columns, $options)
    {
        // TODO - implement
    }
    
    /**
     * {@inheritdoc}
     */
    public function dropIndex($tableName, $columns, $options)
    {
        // TODO - implement
    }
    
    /**
     * {@inheritdoc}
     */
    public function getSqlType($type)
    {
        switch ($type) {
            case 'primary_key':
                return self::DEFAULT_PRIMARY_KEY;
            case 'string':
                return array('name' => 'varchar', 'limit' => 255);
                break;
            case 'text':
                return array('name' => 'text');
                break;
            case 'integer':
                return array('name' => 'int', 'limit' => 11);
                break;
            case 'float':
                return array('name' => 'float');
                break;
            case 'decimal':
                return array('name' => 'decimal');
                break;
            case 'datetime':
                return array('name' => 'datetime');
                break;
            case 'timestamp':
                return array('name' => 'datetime');
                break;
            case 'time':
                return array('name' => 'time');
                break;
            case 'date':
                return array('name' => 'date');
                break;
            case 'binary':
                return array('name' => 'blob');
                break;
            case 'boolean':
                return array('name' => 'tinyint', 'limit' => 1);
                break;
            default:
                throw new \RuntimeException('The type: "' . $type . '" is not supported.');
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function createDatabase($name, $options = array())
    {
        $charset = isset($options['charset']) ? $options['charset'] : 'utf8';
        
        if (isset($options['collation'])) {
            $this->execute(sprintf(
                'CREATE DATABASE `%s` DEFAULT CHARACTER SET `%s` COLLATE `%s`', $name, $charset, $options['collation']
            ));
        } else {
            $this->execute('CREATE DATABASE `%s` DEFAULT CHARACTER SET `%s`', $name, $charset);
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function dropDatabase($name)
    {
        $this->execute(sprintf('DROP DATABASE IF EXISTS `%s`', $name));
    }
}