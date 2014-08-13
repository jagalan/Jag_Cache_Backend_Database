<?php
$this->startSetup();

$this->getConnection()->addColumn(
    $this->getTable('core/cache_tag'),
    'expire_time',
    "int(11) unsigned comment 'expiration time for the tag-key association'"
);

$this->getConnection()->addKey(
    $this->getTable('core/cache_tag'),
    'expire_time',
    'expire_time'
);

$this->endSetup();