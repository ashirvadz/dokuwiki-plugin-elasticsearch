<?php
/**
 * Options for the elasticsearch plugin
 *
 * @author Kieback&Peter IT <it-support@kieback-peter.de>
 */

$meta['servers']      = array();
$meta['indexname']    = array('string');
$meta['documenttype'] = array('string');

$meta['field1'] = array('string');
$meta['field2'] = array('string');
$meta['field3'] = array('string');

$meta['perpage']      = array('numeric', '_min' => 1);
$meta['debug']        = array('onoff');

