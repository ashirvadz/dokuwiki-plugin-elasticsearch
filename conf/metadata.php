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
//$meta['heading'] = array('string');
//$meta['content'] = array('string');

//$meta['snippets']     = array('multichoice', '_choices' => array('content','abstract', 'heading'));
//$meta['snippets2']     = array('multichoice', '_choices' => array('content','abstract', 'heading'));

$meta['perpage']      = array('numeric', '_min' => 1);
$meta['debug']        = array('onoff');

