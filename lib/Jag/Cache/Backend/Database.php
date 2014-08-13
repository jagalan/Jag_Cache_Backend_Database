<?php

/**
 * Database based cache backend that stores an expiration time on tags to allow cleaning tags
 * associated to keys that have expired
 *
 * @author Juan Antonio Galán Martínez <juan.galan.martinez@gmail.com>
 * @license
 */

class Jag_Cache_Backend_Database extends Varien_Cache_Backend_Database implements Zend_Cache_Backend_ExtendedInterface
{
	/**
     * Constructor
     *
     * @param array $options associative array of options
     */
    public function __construct($options = array())
    {
        //To avoid changing Mage_Core_Model_Cache we need to set the options here
        $options = array();
        $options['slow_backend'] = 'Jag_Cache_Backend_Database';
        //WARNING: No easy way to get this parameter value, hardcoding to false for now as it's usually the prefered setting
        $options['store_data'] = false;
        if(empty($options['adapter_callback'])) {
            $options['adapter_callback'] = array($this, 'getDbAdapter');
        }

        if (empty($options['data_table']) || empty ($this->_options['tags_table'])) {
            $options['data_table'] = Mage::getSingleton('core/resource')->getTableName('core/cache');
            $options['tags_table'] = Mage::getSingleton('core/resource')->getTableName('core/cache_tag');
        }
        parent::__construct($options);
    }

    public function getDbAdapter()
    {
        return Mage::getSingleton('core/resource')->getConnection('core_write');
    }

    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * Note: The parent method has been modified to call the new function that saves tags with a lifetime,
     * the rest remains unchanged
     *
     * @param  string $data            Datas to cache
     * @param  string $id              Cache id
     * @param  array $tags             Array of strings, the cache record will be tagged by each string entry
     * @param  int   $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean true if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        $lifetime = $this->getLifetime($specificLifetime);
        $time     = time();
        $expire   = ($lifetime === 0 || $lifetime === null) ? 0 : $time+$lifetime;

        if ($this->_options['store_data']) {
            $adapter    = $this->_getAdapter();
            $dataTable  = $this->_getDataTable();

            $dataCol    = $adapter->quoteIdentifier('data');
            $expireCol  = $adapter->quoteIdentifier('expire_time');
            $query = "INSERT INTO {$dataTable} (
                    {$adapter->quoteIdentifier('id')},
                    {$dataCol},
                    {$adapter->quoteIdentifier('create_time')},
                    {$adapter->quoteIdentifier('update_time')},
                    {$expireCol})
                VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE
                    {$dataCol}=VALUES({$dataCol}),
                    {$expireCol}=VALUES({$expireCol})";

            $result = $adapter->query($query, array($id, $data, $time, $time, $expire))->rowCount();
            if (!$result) {
                return false;
            }
        }
        $tagRes = $this->_saveTagsWithLifetime($id, $tags, $expire);
        return $tagRes;
    }

    /**
     * Save tags related to specific id
     *
     * @param string $id
     * @param array $tags
     * @return bool
     */
    protected function _saveTagsWithLifetime($id, $tags, $lifetime)
    {
        if (!is_array($tags)) {
            $tags = array($tags);
        }
        if (empty($tags)) {
            return true;
        }

        $adapter = $this->_getAdapter();
        $tagsTable = $this->_getTagsTable();
        $select = $adapter->select()
            ->from($tagsTable, 'tag')
            ->where('cache_id=?', $id)
            ->where('tag IN(?)', $tags);

        $existingTags = $adapter->fetchCol($select);
        $insertTags = array_diff($tags, $existingTags);
        if (!empty($insertTags)) {
            $query = 'INSERT IGNORE INTO ' . $tagsTable . ' (tag, cache_id, expire_time) VALUES ';
            $bind = array();
            $lines = array();
            foreach ($insertTags as $tag) {
                $lines[] = '(?, ?, ?)';
                $bind[] = $tag;
                $bind[] = $id;
                $bind[] = $lifetime;
            }
            $query.= implode(',', $lines);
            $adapter->query($query, $bind);
        }
        $result = true;
        return $result;
    }

    /**
     * Clean some cache records
     *
     * Available modes are :
     * Zend_Cache::CLEANING_MODE_ALL (default)    => remove all cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_OLD              => remove too old cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_MATCHING_TAG     => remove cache entries matching all given tags
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG => remove cache entries not {matching one of the given tags}
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG => remove cache entries matching any given tags
     *                                               ($tags can be an array of strings or a single string)
     *
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @return boolean true if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        $adapter = $this->_getAdapter();
        switch($mode) {
            case Zend_Cache::CLEANING_MODE_ALL:
                if ($this->_options['store_data']) {
                    $result = $adapter->query('TRUNCATE TABLE '.$this->_getDataTable());
                } else {
                    $result = true;
                }
                $result = $result && $adapter->query('TRUNCATE TABLE '.$this->_getTagsTable());
                break;
            case Zend_Cache::CLEANING_MODE_OLD:
                if ($this->_options['store_data']) {
                    $result = $adapter->delete($this->_getDataTable(), array(
                        'expire_time> ?' => 0,
                        'expire_time<= ?' => time()
                    ));
                } else {
                    $result = true;
                }
                $adapter->delete($this->_getTagsTable(), array(
                        'expire_time> ?' => 0,
                        'expire_time<= ?' => time()
                    ));
                break;
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                $result = $this->_cleanByTags($mode, $tags);
                break;
            default:
                Zend_Cache::throwException('Invalid mode for clean() method');
                break;
        }

        return $result;
    }
}