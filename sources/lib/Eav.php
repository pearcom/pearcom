<?php

require_once 'Eav/Row.php';

/**
 * Eav class
 *
 * @author Taras Taday
 * @version 0.1.2
 */
class Eav
{
    /*
     * Name of primary table
     */
    protected $_entityTableName = 'eav_entity';
    protected $_entityFieldId = 'id';

    /*
     * Name of attribute table
     */
    protected $_attributeTableName = 'eav_attribute';
    protected $_attributeFieldId   = 'id';
    protected $_attributeFieldType = 'type';
    protected $_attributeFieldName = 'name';

    /**
     * Attributes model
     * @var Zend_Db_Table_Abstract
     */
    protected $_attributeModel;
    protected $_attributes;

    protected $_cache = false;
    protected $_cacheData = array();

    /*
     * Eav model objects
     */
    protected $_eavModels = array();

    public function  __construct(Zend_Db_Table_Abstract $table)
    {
        $this->_entityTableName = $this->getTableName($table);
        $this->_entityModel = $table;
        $this->_attributeModel = new Zend_Db_Table($this->_attributeTableName);
    }

    public function getTableName($table)
    {
        return $table->info('name');
    }


    public function getEavTableName($type)
    {
        return $this->_entityTableName . '_' . strtolower($type);
    }

    public function getEavModel($attribute)
    {
        $type = $this->getAttributeType($attribute);
        if (!isset($this->_eavModels[$type])) {
            $eavTable = new Zend_Db_Table($this->getEavTableName($type));
            $this->_eavModels[$type] = $eavTable;
        }

        return $this->_eavModels[$type];
    }

    public function getEavModels($attributes)
    {
        $models = array();
        foreach ($attributes as $attribute) {
            $type = $this->getAttributeType($attribute);
            if (!isset($models[$type])) {
                $models[$type] = $this->getEavModel($attribute);
            }
        }
        return $models;
    }

    public function getAttributeType($attribute)
    {
        return $attribute->{$this->_attributeFieldType};
    }

    public function getAttributeId($attribute)
    {
        return $attribute->{$this->_attributeFieldId};
    }

    public function getAttributeName($attribute)
    {
        return $attribute->{$this->_attributeFieldName};
    }

    public function getEntityId($row)
    {
        return $row->{$this->_entityFieldId};
    }

    public function getAttribute($id)
    {
        if (isset($this->_attributes[$id])) {
            return $this->_attributes[$id];
        } elseif (is_numeric($id)) {
            $attribute = $this->_attributeModel->find($id)->current();
        } else {
            $where = $this->_attributeModel->select()->where($this->_attributeFieldName . ' = ?', $id);
            $attribute = $this->_attributeModel->fetchRow($where);
        }
        if($attribute)
        {
            $this->cacheAttribute($attribute);
        }
        return $attribute;
    }

    public function cacheAttribute($attribute)
    {
        $this->_attributes[$this->getAttributeId($attribute)] = $attribute;
        $this->_attributes[$this->getAttributeName($attribute)] = $attribute;
    }

    public function cacheAttributes($attributes)
    {
        foreach ($attributes as $attribute) {
            $this->cacheAttribute($attribute);
        }
    }

    public function getValueRow($entityRow, $attributeRow)
    {
        $eavModel = $this->getEavModel($attributeRow);
        $attributeId = $this->getAttributeId($attributeRow);
        $where = $eavModel->select()
                          ->where('entity_id = ?', $this->getEntityId($entityRow))
                          ->where('attribute_id = ?', $attributeId);
        return $eavModel->fetchRow($where);
    }

    /**
     * Return attribute value
     * 
     * @param Zend_Db_Table_Row $row entity object
     * @param Zend_Db_Table_Row $attribute attribute object
     * @param boolean $reload set true to force reload entity value
     * @return mixed
     */
    public function getAttributeValue($row, $attribute, $reload = false)
    {
        if (is_string($attribute)) {
            $attribute = $this->getAttribute($attribute);
        }
        $attributeId = $this->getAttributeId($attribute);
        if (!$reload && $row instanceof Eav_RowInterface && $row->hasAttributeValue($attributeId)) {
            return $row->getAttributeValue($attributeId);
        }
        $valueRow = $this->getValueRow($row, $attribute);

        $value = $valueRow ? $valueRow->value : '';

        if ($row instanceof Eav_RowInterface) {
            $row->setAttributeValue($attributeId, $value);
        }

        return $value;
    }

    /**
     * Set attribute value
     * 
     * @param Zend_Db_Table_Row $entityRow entity object
     * @param Zend_Db_Table_Row $attribute attribute object
     * @param mixed $value attribute value
     */
    public function setAttributeValue($entityRow, $attribute, $value)
    {
        if (is_string($attribute)) {
            $attribute = $this->getAttribute($attribute);
        }
        $eavModel = $this->getEavModel($attribute);
        $valueRow = $this->getValueRow($entityRow, $attribute);
        if (!$valueRow) {
            $valueRow = $eavModel->createRow();
            $valueRow->attribute_id = $this->getAttributeId($attribute);
            $valueRow->entity_id = $this->getEntityId($entityRow);
        }

        $valueRow->value = $value;
        $valueRow->save();
        if ($valueRow instanceof Eav_RowInterface) {
            $valueRow->setAttributeValue($attribute, $value);
        }
    }

    /**
     * Load attributes values with single query
     * 
     * @param Zend_Db_Table_Rowset $rows
     * @param Zend_Db_Table_Rowset $attributes
     * @return array
     */
    public function loadAttributes($rows, $attributes)
    {
        if (!$rows->valid()) {
            return;
        }
        $this->cacheAttributes($attributes);

        $result = array();
        $entities = array();
        foreach ($rows as $row) {
            $entities[$this->getEntityId($row)] = $row;
        }

        $eavModels = $this->getEavModels($attributes);

        $queries = array();
        $entityIds = array_keys($entities);
        foreach ($eavModels as $type => $eavModel) {
            $select = $eavModel->select();
            $select->where('entity_id IN(?)', $entityIds);

            $attributeIds = array();
            foreach ($attributes as $attribute) {
                if ($type == $this->getAttributeType($attribute)) {
                    $attributeIds = $this->getAttributeId($attribute);
                }
            }
            $select->where('attribute_id IN(?)', $attributeIds);
            $queries[] = $select->__toString();
        }

        /* build query */
        $query = '(' . implode(') UNION ALL (', $queries) . ')';

        $db = Zend_Db_Table::getDefaultAdapter();
        $rows = $db->fetchAll($query);
        foreach ($rows as $row) {
            $attributeId = $this->getAttributeId($this->getAttribute($row['attribute_id']));
            $entities[$row['entity_id']]->setAttributeValue($attributeId, $row['value']);
        }
        return $rows;
    }
}