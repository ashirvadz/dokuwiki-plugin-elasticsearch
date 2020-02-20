<?php
/**
 * Default settings for the elasticsearch plugin
 *
 * @author Kieback&Peter IT <it-support@kieback-peter.de>
 */

$conf['servers']      = 'localhost:9200';
$conf['indexname']    = 'wiki';
$conf['documenttype'] = 'wikipage';

$conf['field1']     = 'book';
$conf['field2']     = 'heading';
$conf['field3']     = 'content';
//$conf['book'] = 'book';
//$conf['heading'] = 'heading';
//$conf['content'] = 'content';

$conf['perpage']      = 20;
$conf['debug']        = 0;

