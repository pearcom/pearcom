<?php

require_once 'Zend/Db/Table/Row/Abstract.php';
require_once 'Eav/Row/Interface.php';

/**
 * @author Taras Taday
 */
class Eav_Row extends Zend_Db_Table_Row_Abstract implements Eav_Row_Interface
{
    protected $_attributes = array();

    public function setAttributeValue($attributeId, $value)
    {
        $this->_attributes[$attributeId] = $value;
    }

    public function hasAttributeValue($attributeId)
    {
        return isset($this->_attributes[$attributeId]);
    }

    public function getAttributeValue($attributeId)
    {
        return $this->_attributes[$attributeId];
    }
}