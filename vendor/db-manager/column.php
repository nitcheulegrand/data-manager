<?php
namespace DBManager;

class Column {
    private string $name;
    private string $type;
    private string $null;
    private string $key;
    private $default;
    private $extra;
    public function __construct($name, $type, $null=null, $key=null, $default=null, $extra=null)
    {
        $this->name = $name;
        $this->type = $type;
        $this->null = $null;
        $this->key = $key;
        $this->default = $default;
        $this->extra = $extra;
    }
    public function getMetadata() {
        $str = $this->getName() . " " . $this->type;
        $str .= " " . ($this->canBeNull() ? "NULL" : "NOT NULL");
        if ($this->getDefaultValue()!="") $str .= " DEFAULT {$this->getDefaultValue()}";
        if ($this->isUniqueKey()) $str .= " UNIQUE";
        if ($this->isPrimaryKey()) $str .= " PRIMARY KEY";
        if ($this->isAutoIncrement()) $str .= " AUTO_INCREMENT";
        return $str;
    }
    public function getName() {
        return $this->name;
    }
    public function getType() {
        if (\substr_count($this->type, "tinyint(1)")!=0) return "bool";
        if (\substr_count($this->type, "int")!=0) return "int";
        if (\substr_count($this->type, "float")!=0) return "float";
        if (\substr_count($this->type, "double")!=0) return "float";
        if (\substr_count($this->type, "char")!=0) return "string";
        if (\substr_count($this->type, "text")!=0) return "string";
        return $this->type;
    }
    public function canBeNull() {
        return $this->null=="YES";
    }
    public function getDefaultValue() {
        return $this->default;
    }
    public function isPrimaryKey() {
        return $this->key=="PRI";
    }
    public function isUniqueKey() {
        return $this->key=="UNI";
    }
    public function isForeignKey() {
        return $this->key=="MUL";
    }
    public function isAutoIncrement() {
        return \substr_count($this->extra, "auto_increment")!=0;
    }

    public function isEqual(Column $column) {
        return (
            $this->getName()==$column->getName() &&
            $this->getType()==$column->getType() &&
            $this->canBeNull()==$column->canBeNull() &&
            $this->getDefaultValue()==$column->getDefaultValue() &&
            $this->isPrimaryKey()==$column->isPrimaryKey() &&
            /*$this->isForeignKey()==$column->isForeignKey() && */
            $this->isAutoIncrement()==$column->isAutoIncrement() 
        );
    }



    public function __toString() {
        return "\"{$this->getName()}\": \"{$this->getType()}\"";
    }
}