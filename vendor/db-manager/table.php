<?php

namespace DBManager;

use PDO;

class Table {
    private DB $dbConnection;
    private string $tableName;

    /**
     * @var Column[]
     */
    private $columns = [];

    /**
     * @var Relation[]
     */
    private $relations = [];

    private $__table_description_collected = false;
    private $__table_relations_loaded = false;


    function __construct(DB $dbConnection, string $tableName, $exist=true) 
    {
        $this->dbConnection = $dbConnection;
        $this->tableName = $tableName;
        if ($exist) $this->getTableDescription();
    }

    public function getDB() {
        return $this->dbConnection;
    }
    public function getTableDescription() {
        if (!$this->__table_description_collected) {
            // Load description
            $columns = $this->dbConnection->query("DESCRIBE {$this->tableName};");
            foreach ($columns as $column) {
                \array_push($this->columns, new Column($column->Field, $column->Type, $column->Null, $column->Key, $column->Default, $column->Extra));
            }
            $this->__table_description_collected = true;
        }
    }
    /**
     * @return string
     */
    public function getTableCreationScript() {
        //if ($this->__table_description_collected) {
            $res = $this->dbConnection->getConnection()->query("SHOW CREATE TABLE {$this->tableName};");
            $res = $res->fetch();
            return $res[1];
        //}
    }
    public function getName() {
        return $this->tableName;
    }
    public function getColumns() {
        return $this->columns;
    }
    public function setColumns($columns) {
        $this->columns = $columns;
    }
    public function create() {
        $sql = "CREATE TABLE {$this->getName()} (";
        foreach ($this->getColumns() as $key => $column) {
            if ($key > 0) $sql .= ", ";
            $sql .= $column->getMetadata();
        }
        $sql .= ")";
        return $this->dbConnection->execute($sql) !== FALSE;
    }
    public function delete() {
        $sql = "DROP TABLE {$this->getName()}; ";
        $this->dbConnection->execute($sql);
        return $this;
    }
    public function isEqual(Table $table) {
        if (
            $this->getName()!=$table->getName() || 
            sizeof($this->getColumns())!=sizeof($table->getColumns())
        ) return false;
        else {
            foreach ($this->getColumns() as $column) {
                if (!$table->hasColumn($column)) return false;
            }
        }
        return true;
    }
    public function hasColumn(Column $column) {
        foreach ($this->getColumns() as $c) {
            if ($c->isEqual($column)) return true;
        }
        return false;
    }

    public function addRelation(Relation $relation) {
        $r = new Relation(
            $this,
            $relation->getType(),
            $relation->getFieldName(),
            $relation->getKeyName(),
            $relation->getConstraintName(),
            $relation->getRefTableName(),
            $relation->getRefTableKey(),
            $relation->getExtra()
        );
        $r->create();
        \array_push($this->relations, $r);
    }
    public function removeRelation(Relation $relation) {
        $index = $this->getIndexOfRelationByConstraintName($relation->getConstraintName());
        if ($index!=-1) {
            $r = $this->relations[$index];
            $r->remove();
            $this->relations = \array_slice($this->relations, $index, 1);
        }
    }
    public function getColumnByName(string $columName, $strict=false) {
        foreach ($this->getColumns() as $column) {
            if ($strict AND $column->getName()==$columName) return $column;
            if (!$strict AND \strtoupper($column->getName())==\strtoupper($columName)) return $column;
        }
        return null;
    }
    public function getRelationByConstraintName(string $constraintName, $strict=false) {
        foreach ($this->getRelations() as $relation) {
            if ($strict AND $relation->getConstraintName()==$constraintName) return $relation;
            if (!$strict AND \strtoupper($relation->getConstraintName())==\strtoupper($constraintName)) return $relation;
        }
        return null;
    }
    public function getRelationByFieldName(string $fieldName, $strict=false) {
        foreach ($this->getRelations() as $relation) {
            if ($strict AND $relation->getFieldName()==$fieldName) return $relation;
            if (!$strict AND \strtoupper($relation->getFieldName())==\strtoupper($fieldName)) return $relation;
        }
        return null;
    }
    public function getIndexOfRelationByConstraintName(string $constraintName, $strict=false) {
        $index = -1;
        foreach ($this->getRelations() as $key => $relation) {
            if ($strict AND $relation->getConstraintName()==$constraintName) return $key;
            if (!$strict AND \strtoupper($relation->getConstraintName())==\strtoupper($constraintName)) return $key;
        }
        return $index;
    }

    /**
     * @todo
     * Go in related table and add relations 
     * in other to create couples (oneToOne/oneToOne, oneToMany/manyToOne)
     */
    public function getRelations() {
        if (!$this->__table_relations_loaded) {
            $script = $this->getTableCreationScript();
            $arr = \explode("\n", $script);
            $keys = [];
            $constraints = [];
            foreach ($arr as $a) {
                $matches = explode("`", $a);
                if (\sizeof($matches)==5 AND substr_count($matches[0], "PRIMARY")==0) {
                    \array_push($keys, [
                        "keyName" => $matches[1],
                        "fieldName" => $matches[3],
                        "isUniqueKey" => substr_count($matches[0], "UNIQUE")!=0
                    ]);
                }
                if (\sizeof($matches)==9 AND substr_count($matches[0], "PRIMARY")==0) {
                    \array_push($constraints, [
                        "constraintName" => $matches[1],
                        "fieldName" => $matches[3],
                        "refTableName" => $matches[5],
                        "refTableKey" => $matches[7],
                        "extra" => \substr(str_replace(",", "", $matches[8]), 1) 
                    ]);
                }
            }
            $this->relations = [];
            foreach ($constraints as $c) {
                $key = null;
                foreach ($keys as $k) {
                    if ($k["fieldName"]==$c["fieldName"]) {
                        $key = $k;
                        break;
                    }
                }
                \array_push($this->relations, new Relation(
                    $this,
                    ($key==null OR !$key["isUniqueKey"]) ? "ManyToOne" : "OneToOne",
                    $c["fieldName"],
                    ($key!=null) ? $key["keyName"] : "",
                    $c["constraintName"],
                    $c["refTableName"],
                    $c["refTableKey"],
                    $c["extra"]
                ));
            }
            $this->__table_relations_loaded = true;
        }
        return $this->relations;
    }

    /**
     * @todo
     * return empty array if every thin is Ok
     * return an array on column to add, column to remove
     * relations to add, relations to be removed
     */
    public function compare(Table $table) {
        $added = [];
        $updated = [];
        $removed = [];
        // On cherche dans la table miroir les champs n'existants pas dans la table source
        // ou ayant été modifiés (pour ajouts et modifications).
        foreach ($table->getColumns() as $column) {
            $c = $this->getColumnByName($column->getName());
            if ($c==null) {
                \array_push($added, $column);
            }
            elseif (!$c->isEqual($column)) {
                \array_push($updated, $column);
            }
        }
        // On cherche dans la sources les champs ne figurants pas à la destination (pour suppressions)
        foreach ($this->getColumns() as $column) {
            $c = $table->getColumnByName($column->getName());
            if ($c==null) {
                \array_push($removed, $column);
            }
        }
        return new TableCompare($added, $updated, $removed);
    }

    public function merge(Table $table, $twice=true) {
        $dumpSql = "";
        $compareResult = $this->compare($table);
        if (!$compareResult->isEqual()) {
            $sql = "";
            // Updated columns
            foreach ($compareResult->getUpdated() as $column) {
                $sql .= "ALTER TABLE {$this->getName()} CHANGE COLUMN {$column->getName()} {$column->getMetadata()}; \n";
            }
            // Added columns
            foreach ($compareResult->getAdded() as $column) {
                $sql .= "ALTER TABLE {$this->getName()} ADD COLUMN {$column->getMetadata()}; \n";
            }
            if (!$twice) {
                // Removed columns
                foreach ($compareResult->getRemoved() as $column) {
                    $sql .= "ALTER TABLE {$this->getName()} DROP COLUMN {$column->getName()}; \n";
                }
            }
            $dumpSql .= $sql;
            $this->dbConnection->execute($sql);
            if ($twice) {
                // Add absent columns in destination table
                $sql = "";
                foreach ($compareResult->getRemoved() as $column) {
                    $sql .= "ALTER TABLE {$this->getName()} ADD COLUMN {$column->getMetadata()}; \n";
                }
                $dumpSql .= $sql;
                if ($sql != "") $table->getDB()->execute($sql);
            }
        }
        // Adding relations
        foreach ($this->getRelations() as $relation) {
            $r = $table->getRelationByConstraintName($relation->getConstraintName());
            if ($r==null) {
                $table->addRelation($relation);
            }
            elseif (!$relation->isEqual($r) && $relation->getRefTableName()==$r->getRefTableName() && $relation->getRefTableKey()==$r->getRefTableKey()) {
                $r->updateKey($relation->getKeyName());
            }
            elseif (!$relation->isEqual($r)) {
                $table->removeRelation($r);
                $table->addRelation($relation);
            }
        }
        foreach ($table->getRelations() as $r) {
            $relation = $this->getRelationByConstraintName($r->getConstraintName());
            if ($relation==null && $twice) {
                // Ajouter la relation de la table de destination dans la table source car il n'y existe pas
                $this->addRelation($r);
            }
            elseif ($relation!=null && !$twice) {
                // Retirer la relation de la table de destination car il n'existe pas dans la table source
                $table->removeRelation($r);
            }
        }
        return [
            "added" => ($twice ? sizeof($compareResult->getRemoved()) : 0) + sizeof($compareResult->getAdded()),
            "updated" => sizeof($compareResult->getUpdated()),
            "removed" => $twice ? 0 : sizeof($compareResult->getRemoved()),
            "dump_sql" => $dumpSql
        ];
    }



    /** *************************************************************
     * CRUD
     * Data manipalation functions
     * 
     * **************************************************************
     */

    public function getOneData($id) {
        $key = null;
        foreach ($this->getColumns() as $column) {
            if ($column->isPrimaryKey()) $key = $column;
            if ($column->isUniqueKey() && $key==null) $key = $column;
        }
        if (!$key) return null;
        $sql = "SELECT * FROM {$this->tableName} WHERE {$key->getName()}=:id";
        $connexion = $this->dbConnection->getConnection();
        if ($connexion!=null) {
            $st = $connexion->prepare($sql);
            $st->execute([":id" => $id]);
            //if (!empty($st->errorInfo()[2])) echo "<pre>";print_r($st->errorInfo());echo "</pre>";
            $data = \json_decode(\json_encode($st->fetch(PDO::FETCH_ASSOC)));
            return $data;
        }
        else return null;
    }
    public function getAllData() {
        $data = $this->dbConnection->query("SELECT * FROM {$this->tableName}");
        return $data;
    }
    public function saveOneData($object) {
        $columns = $this->getColumns();
        $bindKeys = []; 
        $bindValues = [];
        $sql = "INSERT INTO ".$this->getName()." (";
        $i = 0;
        foreach ($columns as $column) {
            $value = $this->getColumnValueFromObject($object, $column->getName());
            if ($value !== \UNSET) {
                if ($i==0) {
                    $sql .= $column->getName(); 
                    $i++;
                }
                else $sql .= ", " . $column->getName();
                $values[] = $value;
                $bindKeys[] = ":{$column->getName()}";
                $bindValues[":{$column->getName()}"] = $value;
            }
        }
        $sql .= ") VALUES (" . \implode(", ", $bindKeys) . ")";//print_r($bindValues);die($sql);
        $connexion = $this->dbConnection->getConnection();
        if ($connexion!=null) {
            $st = $connexion->prepare($sql);
            $st->execute($bindValues);
            //if (!empty($st->errorInfo()[2])) echo "<pre>";print_r($st->errorInfo());echo "</pre>";
            $result = $this->getOneData($connexion->lastInsertId());
            return $result;
        }
        else return null;
    }
    public function saveAllData(array $array_object) {
        $result = [];
        foreach ($array_object as $key => $object) {
            $result[$key] = $this->saveOneData($object);
        }
        return $result;
    }



    
    public function __toString() {
        $c = [];
        foreach($this->getColumns() as $column) {
            $c[$column->getName()] = $column->getType();
        }
        return \json_encode([
            "name" => $this->getName(),
            "columns" => $c
        ]);
    }



    private function getColumnValueFromObject($object, $prop) {
        /**
         * @todo
         * Check variations of $prop in $object 
         * to avoid sever mode
         */
        if (isset($object->$prop)) return $object->$prop;
        else return \UNSET;
    }
}

class TableCompare {
    /**
     * @var Column[]
     */
    private $added;
    /**
     * @var Column[]
     */
    private $removed;
    /**
     * @var Column[]
     */
    private $updated;


    public function __construct($added, $updated, $removed) {
        $this->added = $added;
        $this->updated = $updated;
        $this->removed = $removed;
    }
    
    /**
     * @return Column[]|null
     */
    public function getAdded() {
        return \is_array($this->added) ? $this->added : [];
    }
    
    /**
     * @return Column[]|null
     */
    public function getUpdated() {
        return \is_array($this->updated) ? $this->updated : [];
    }
    
    /**
     * @return Column[]|null
     */
    public function getRemoved() {
        return \is_array($this->removed) ? $this->removed : [];
    }

    public function isEqual() {
        return \sizeof($this->added)==0 && \sizeof($this->updated)==0 && sizeof($this->removed)==0;
    }
}


class Relation {
    private string $keyName;
    private string $fieldName;
    private string $constraintName;
    private string $refTableName;
    private string $refTableKey;
    private string $extra;

    private Table $table;

    /**
     * @var string
     * Value in ["OneToOne", "OneToMany", "ManyToOne", "ManyToMany"]
     */
    private string $type;

    public function __construct($table, $type, $fieldName, $keyName, $constraintName, $refTableName, $refTableKey, $extra="")
    {
        $this->table = $table;
        $this->type = $type;

        $this->fieldName = $fieldName;
        $this->keyName = $keyName;
        $this->constraintName = $constraintName;
        $this->refTableName = $refTableName;
        $this->refTableKey = $refTableKey;
        $this->extra = $extra;
    }
    public function getTable() {
        return $this->table;
    }
    public function getType() {
        return $this->type;
    }
    public function getFieldName() {
        return $this->fieldName;
    }
    public function getKeyName() {
        return $this->keyName;
    }
    public function getConstraintName() {
        return $this->constraintName;
    }
    public function getRefTableName() {
        return $this->refTableName;
    }
    public function getRefTableKey() {
        return $this->refTableKey;
    }
    public function getExtra() {
        return $this->extra;
    }

    public function isEqual(Relation $relation) {
        return (
            $this->getFieldName()==$relation->getFieldName() && 
            $this->getKeyName()==$relation->getKeyName() && 
            $this->getConstraintName()==$relation->getConstraintName() && 
            $this->getRefTableName()==$relation->getRefTableName() && 
            $this->getRefTableKey()==$relation->getRefTableKey() && 
            $this->getExtra()==$relation->getExtra()
        );
    }

    public function create() {
        $sql = "";
        if (in_array(\strtolower($this->getType()), ["manytoone", "onetoone"])) {
            $sql = "ALTER TABLE {$this->getTable()->getName()} ADD CONSTRAINT `{$this->getConstraintName()}` FOREIGN KEY (`{$this->getFieldName()}`) REFERENCES `{$this->getRefTableName()}` (`{$this->getRefTableKey()}`){$this->getExtra()};";
        }
        if ($sql!="") {
            $this->getTable()->getDB()->execute($sql);
        }
    }

    public function remove() {
        $sql = "";
        if (in_array(\strtolower($this->getType()), ["manytoone", "onetoone"])) {
            $sql = "ALTER TABLE {$this->getTable()->getName()} DROP  FOREIGN KEY `{$this->getConstraintName()}`;";
        }
        if ($sql!="") {
            $this->getTable()->getDB()->execute($sql);
        }
    }

    public function updateKey(string $newKeyName) {
        if ($this->getKeyName()!="") {
            $sql = "ALTER TABLE {$this->getTable()->getName()} RENAME KEY `{$this->getKeyName()}` TO `$newKeyName`;";
            $this->getTable()->getDB()->execute($sql);
            $this->keyName = $newKeyName;
            return true;
        }
        return false;
    }
}


\define('UNSET', "__no_data_is_given_for_the_concern_variable__");