<?php namespace OrmExtension\Extensions;

use Config\OrmExtension;
use IteratorAggregate;
use OrmExtension\DataMapper\EntityTrait;
use OrmExtension\DataMapper\ModelDefinitionCache;
use OrmExtension\DataMapper\QueryBuilderInterface;
use OrmExtension\DataMapper\RelationDef;

/**
 * Class Entity
 * @package OrmExtension\Extensions
 * @property array $all
 * @property array $stored
 * @property array $hiddenFields
 *
 * @property int $id
 */
class Entity extends \CodeIgniter\Entity implements IteratorAggregate {
    use EntityTrait;

    public $hiddenFields = [];
    public $all = null;

    /**
     * Takes an array of key/value pairs and sets them as
     * class properties, using any `setCamelCasedProperty()` methods
     * that may or may not exist.
     *
     * @param array $data
     * @return Entity
     */
    public function fill(?array $data = NULL) {
        if($data) {
            $fields = $this->getTableFields();
            $relations = ModelDefinitionCache::getRelations($this->getSimpleName());
            /** @var RelationDef[] $relationName2Relation */
            $relationName2Relation = [];
            foreach($relations as $relation) $relationName2Relation[$relation->getSimpleName()] = $relation;

            foreach($data as $key => $value) {
                $method = 'set' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $key)));

                if(method_exists($this, $method)) {
                    $this->$method($value);
                } else { // Does not require property to exist, properties are managed by OrmExtension from database columns
                    if(in_array($key, $fields)) // Does require field to exists
                        $this->$key = $value;
                    else if(isset($relationName2Relation[singular($key)])) { // Auto-populate relation
                        $relation = $relationName2Relation[singular($key)];
                        switch($relation->getType()) {
                            case RelationDef::HasOne:
                                $entityName = $relation->getEntityName();
                                $this->$key = new $entityName($value);
                                break;
                            case RelationDef::HasMany:
                                $entityName = $relation->getEntityName();
                                $this->{$key} = new $entityName();
                                /** @var Entity $relationMany */
                                $relationMany = $this->{$key};
                                foreach($value as $v) {
                                    $relationMany->add(new $entityName($v));
                                }
                                break;
                        }
                    }
                }
            }
        }

//        $this->resetStoredFields();
        return $this;
    }

    private function getSimpleName() {
        return substr(strrchr(get_class($this), '\\'), 1);
    }

    private $_model;

    /**
     * @return Model|QueryBuilderInterface
     */
    public function _getModel() {
        if(!$this->_model) {
            foreach(OrmExtension::$modelNamespace as $modelNamespace) {
                $name = $modelNamespace . $this->getSimpleName() . 'Model';
                if(class_exists($name)) {
                    $this->_model = new $name();
                    break;
                }
            }
        }
        return $this->_model;
    }

    /**
     * Override System/Entity, cause of error "Undefined variable: result"
     * @param string $key
     * @return mixed|null
     */
    public function __get(string $key) {
        $result = parent::__get($key);

        if(is_null($result) && $key != 'id') {
            // Check for relation
            foreach($this->_getModel()->getRelations() as $relation) {
                if($relation->getSimpleName() == singular($key)) {
                    $className = $relation->getEntityName();
                    $this->{$key} = new $className();
                    /** @var Entity $entity */
                    $entity = $this->attributes[$key];

                    // Check for hasOne
                    if(in_array($relation->getJoinSelfAs(), $this->getTableFields())) {
                        $entity->_getModel()->where($entity->_getModel()->getPrimaryKey(), $this->{$relation->getJoinSelfAs()});
                    } else
                        $entity->_getModel()->whereRelated($relation->getOtherField(), $this->_getModel()->getPrimaryKey(), $this->{$this->_getModel()->getPrimaryKey()});
                    $result = $entity;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return \CodeIgniter\Entity|Entity
     */
    public function __set(string $key, $value = null) {
        parent::__set($key, $value);
        //if(isset($this->attributes[$key])) $this->$key = $this->attributes[$key];
        return $this;
    }

    /**
     * @return Entity
     */
    public function first() {
        return isset($this->all) ? reset($this->all) : $this;
    }

    public function setAttributes(array $data) {
        // Type casting
        $fieldData = ModelDefinitionCache::getFieldData($this->getSimpleName());
        $fieldName2Type = [];
        foreach($fieldData as $field) $fieldName2Type[$field->name] = $field->type;

        foreach($data as $field => $value) {
            if(isset($fieldName2Type[$field])) {
                switch($fieldName2Type[$field]) {
                    case 'int':
                        $data[$field] = is_null($value) ? null : (int)$value;
                        break;
                    case 'float':
                    case 'double':
                    case 'decimal':
                        $data[$field] = is_null($value) ? null : (double)$value;
                        break;
                    case 'tinyint':
                        $data[$field] = (bool)$value;
                        break;
                    case 'datetime':
                        $data[$field] = $value ? date('Y-m-d H:i:s', strtotime($value)) : null;
                        break;
                    default:
                        $data[$field] = $value;
                }
            }
        }

        return parent::setAttributes($data);
    }

    public function getOriginal($key = null) {
        if($key)
            return $this->original[$key];
        else
            return $this->original;
    }

    public function hasChanged(string $key = null, $checkRelations = false): bool {
        if($key === null && $checkRelations == false) {
            // CI4 will check original against attributes. Attributes holds everything, including relations
            // Remove relations before checking
            $tableFields = [];
            foreach($this->getTableFields() as $tableField)
                $tableFields[$tableField] = $tableField;
            $original = array_intersect_key($this->original, $tableFields);
            $attributes = array_intersect_key($this->attributes, $tableFields);
            return $original !== $attributes;
        } else
            return parent::hasChanged($key);
    }

}
