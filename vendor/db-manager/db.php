<?php
/**
 * @author NITCHEU N. Legrand
 * Design for database management facilities
 */
namespace DBManager;

use DBManager\Table;

class DB {
    private \PDO $pdo;
    private string $dbName;
    private string $dbUser;
    private string $dbPassword;
    private string $dbHost;
    private string $dbPort;
    private string $dbType;
    private string $dbCharset;

    /**
     * @var Table[]
     */
    private $tables = [];

    private bool $__tables_collected = false;

    function __construct($dbName, $dbUser, $dbPassword, $dbHost="localhost", $dbPort=3306, $dbType="mysql", $dbCharset="UTF8") {
        $dsn = "$dbType:host=$dbHost;port=$dbPort;dbname=$dbName;charset=$dbCharset";
        $this->dbName = $dbName;
        $this->dbUser = $dbUser;
        $this->dbPassword = $dbPassword;
        $this->dbHost = $dbHost;
        $this->dbPort = $dbPort;
        $this->dbCharset = $dbCharset;
        $this->pdo = new \PDO($dsn, $this->dbUser, $this->dbPassword);
        // Load tables and relations
        $this->getTables();
    }

    public function getConnection() {
        return $this->pdo;
    }
    public function query(string $sql) {
        $data = $this->getConnection()->query($sql);
        return json_decode(json_encode($data->fetchAll(\PDO::FETCH_ASSOC)));
    }
    public function execute(string $sql) {
        return $this->getConnection()->exec($sql);
    }




    /**
     * Tables manipulations
     * get, search, compare
     */


    public function getTables() {
        if (!$this->__tables_collected) {
            // Load tables
            $tableList = $this->getConnection()->query("SHOW TABLES;")->fetchAll();
            foreach ($tableList as $t) {
                \array_push($this->tables, new Table($this, $t[0]));
            }
            // Load relations
            foreach ($this->tables as $table) {
                $table->getRelations();
            }
            // Avoir reload
            $this->__tables_collected = true;
        }
        return $this->tables;
    }
    public function hasTable(Table $table) {
        foreach ($this->getTables() as $t) {
            if ($t->isEqual($table)) return true;
        }
        return false;
    }
    public function isEqual(DB $db) {
        if (sizeof($this->getTables())!=sizeof($db->getTables())) return false;
        foreach ($this->getTables() as $table) {
            if (!$db->hasTable($table)) return false;
        }
        return true;
    }
    public function getTableByName(string $tableName, $strict=false) {
        foreach ($this->getTables() as $table) {
            if ($strict AND $table->getName()==$tableName) return $table;
            if (!$strict AND \strtoupper($table->getName())==\strtoupper($tableName)) return $table;
        }
        return null;
    }
    public function getIndexOfTableByName(string $tableName) {
        foreach ($this->getTables() as $key => $table) {
            if ($table->getName()!=$tableName) {
                return $key;
            }
        }
        return -1;
    }
    public function addTable(Table $table) {
        if (!$this->hasTable($table)) {
            $t = new Table($this, $table->getName(), false);
            $t->setColumns($table->getColumns());
            $t->create();
            \array_push($this->tables, $t);
        }
    }
    public function removeTable(Table $table) {
        $index = $this->getIndexOfTableByName($table->getName());
        if ($index!=-1) {
            $t = $this->tables[$index];
            $t->delete();
            $this->tables = \array_slice($this->tables, $index, 1);
        }
    }
    public function compare(DB $db) {
        $added = [];
        $updated = [];
        $removed = [];
        // On cherche dans la base de données miroir les tables n'existantes pas dans la table source
        // ou ayant été modifiées (pour ajouts et modifications).
        foreach ($db->getTables() as $table) {
            $t = $this->getTableByName($table->getName());
            if ($t==null) {
                \array_push($added, $table);
            }
            elseif (!$t->isEqual($table)) {
                \array_push($updated, $table);
            }
        }
        // On cherche dans la sources les tables ne figurantes pas à la destination (pour suppressions)
        foreach ($this->getTables() as $table) {
            $t = $db->getTableByName($table->getName());
            if ($t==null) {
                \array_push($removed, $table);
            }
        }
        return new DBCompare($added, $updated, $removed);
    }
    public function merge(DB $db, $twice=true) {
        $dump = [];
        // Merge tables
        $compareResult = $this->compare($db);
        if (!$compareResult->isEqual()) {
            // Added Tables
            foreach ($compareResult->getAdded() as $table) {
                $this->addTable($table);
            }
            // Updated Tables
            foreach ($compareResult->getUpdated() as $table) {
                $t = $this->getTableByName($table->getName());
                if ($t) {
                    $dump[] = $t->merge($table);
                }
            }
            foreach ($compareResult->getRemoved() as $table) {
                if (!$twice) {
                    // Removed Tables
                    $this->removeTable($table);
                } else {
                    // Add Tables in other databases
                    $db->addTable($table);
                }
            }
        }
        // Merge relation
        foreach ($this->getTables() as $table) {
            $table->merge($db->getTableByName($table->getName()));
        }
        // Result
        return [
            "added" => ($twice ? sizeof($compareResult->getRemoved()) : 0) + sizeof($compareResult->getAdded()),
            "updated" => sizeof($compareResult->getUpdated()),
            "removed" => $twice ? 0 : sizeof($compareResult->getRemoved()),
            "dump" => $dump
        ];
    }



    public function __toString()
    {
        $t = [];
        foreach ($this->getTables() as $table) {
            \array_push($t, \json_decode($table->__toString()));
        }
        return \json_encode([
            "name" => $this->dbName,
            "tables" => $t
        ]);
    }
}

class DBCompare {
    /**
     * @var Table[]
     */
    private $added = [];

    /**
     * @var Table[]
     */
    private $updated = [];

    /**
     * @var Table[]
     */
    private $removed = [];

    public function __construct($added, $updated, $removed) {
        $this->added = $added;
        $this->updated = $updated;
        $this->removed = $removed;
    }
    
    /**
     * @return Table[]
     */
    public function getAdded() {
        return \is_array($this->added) ? $this->added : [];
    }
    
    /**
     * @return Table[]
     */
    public function getUpdated() {
        return \is_array($this->updated) ? $this->updated : [];
    }
    
    /**
     * @return Table[]
     */
    public function getRemoved() {
        return \is_array($this->removed) ? $this->removed : [];
    }

    public function isEqual() {
        return \sizeof($this->added)==0 && \sizeof($this->updated)==0 && sizeof($this->removed)==0;
    }
}