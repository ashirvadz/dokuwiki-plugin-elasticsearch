<?php
/**
 * DokuWiki Plugin elasticsearch (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 */

// must be run within Dokuwiki
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\MultiMatch;
use Elastica\Query\SimpleQueryString;
use Elastica\Query\Term;
use Elastica\ResultSet;

if(!defined('DOKU_INC')) die();

/**
 * Main search helper
 */
class action_plugin_elasticsearch_search extends DokuWiki_Action_Plugin {

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(Doku_Event_Handler $controller) {

        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_preprocess');
        $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'handle_action');

    }

    /**
     * allow our custom do command
     *
     * @param Doku_Event $event
     * @param $param
     */
    public function handle_preprocess(Doku_Event $event, $param) {
        if($event->data != 'search') return;
        $event->preventDefault();
        $event->stopPropagation();
    }

    /**
     * do the actual search
     *
     * @param Doku_Event $event
     * @param $param
     */
    public function handle_action(Doku_Event $event, $param) {
        if($event->data != 'search') return;
        $event->preventDefault();
        $event->stopPropagation();
        global $QUERY;
        global $INPUT;
        global $ID;

        if (empty($QUERY)) $QUERY = $INPUT->str('q');
        if (empty($QUERY)) $QUERY = $ID;

        /** @var helper_plugin_elasticsearch_client $hlp */
        $hlp = plugin_load('helper', 'elasticsearch_client');

        /** @var helper_plugin_elasticsearch_form $hlpform */
        $hlpform = plugin_load('helper', 'elasticsearch_form');

        $client = $hlp->connect();
        $index  = $client->getIndex($this->getConf('indexname'));

        // define the main query string
        $qstring = new MultiMatch($QUERY);
        $qstring->setFields([ $this->getConf('field2'),  $this->getConf('field3')]);
        $qstring->setQuery($QUERY);

        // create the main search object
        $equery = new Query();
        $subqueries = new BoolQuery();
        $subqueries->addMust($qstring);

        // Filter only node results
        $efilter = new Term();
        $efilter->setTerm('type', 'node');
        $equery->setPostFilter($efilter);

        $equery->setHighlight(
            [
                "pre_tags"  => ['ELASTICSEARCH_MARKER_IN'],
                "post_tags" => ['ELASTICSEARCH_MARKER_OUT'],
                "fields"    => [
                    $this->getConf('field1') => new stdClass(),
                    $this->getConf('field2') => new stdClass(),
                    $this->getConf('field3') => new stdClass()]
            ]
        );

        /// define the book query string   ///
        $bstring = new SimpleQueryString($QUERY);
        $bstring-> setFields([$this->getConf('field1')]);
        $bstring->setQuery($QUERY);

        /// create the book search object   ///
        $bquery = new Query();
        $bsubqueries = new BoolQuery();
        $bsubqueries->addMust($bstring);

        // Filter only book results
        $bfilter = new Term();
        $bfilter->setTerm('type', 'book');
        $bquery->setPostFilter($bfilter);

        $bquery->setHighlight(
            [
                "pre_tags"  => ['ELASTICSEARCH_MARKER_IN'],
                "post_tags" => ['ELASTICSEARCH_MARKER_OUT'],
                "fields"    => [$this->getConf('field1') => new stdClass()]
            ]
        );

        // paginate
        $equery->setSize($this->getConf('perpage'));
        $equery->setFrom($this->getConf('perpage') * ($INPUT->int('p', 1, true) - 1));

        // Filter according to the language of the library
        $language = substr($_SERVER["DOCUMENT_ROOT"],-2);
        $term = new Term();
        $term->setTerm('language', $language);
        $subqueries->addMust($term);
        $bsubqueries->addMust($term);

        $equery->setQuery($subqueries);
        $bquery->setQuery($bsubqueries);     //  Books

        try {
            $result = $index->search($equery);
            $resultbooks = $index->search($bquery);

            $this->print_intro();
            $hlpform->tpl($aggs['books']['buckets'] ?: []);

            // Print the results
            $this->print_results($resultbooks, 'book');
            $this->print_results($result, 'node') && $this->print_pagination($result);
        } catch(Exception $e) {
            msg('Something went wrong while searching. Please try again later.<br /><pre>' . hsc($e->getMessage()) . '</pre>', -1);
        }
    }


    /**
     * Prints the introduction text
     */
    protected function print_intro() {
        global $QUERY;
        global $ID;
        global $lang;

        // just reuse the standard search page intro:
        $intro = p_locale_xhtml('searchpage');
        // allow use of placeholder in search intro
        $pagecreateinfo = '';
        if (auth_quickaclcheck($ID) >= AUTH_CREATE) {
            $pagecreateinfo = sprintf($lang['searchcreatepage'], $QUERY);
        }
        $intro          = str_replace(
            ['@QUERY@', '@SEARCH@', '@CREATEPAGEINFO@'],
            [hsc(rawurlencode($QUERY)), hsc($QUERY), $pagecreateinfo],
            $intro
        );
        echo $intro;
        flush();
    }

    /**
     * Output the search results
     *
     * @param ResultSet $results
     * @return bool true when results where shown
     * @type string the type of result
     */
    protected function print_results($results, $type) {
        global $lang;

        // output results
        $found = $results->getTotalHits();

        if(!$found) {
            if($type == 'node')
                echo '<h2>' . $lang['nothingfound'] . '</h2>';
            return (bool)$found;
        }

        echo '<dl class="search_results">';
        if($type=='node')
            echo '<h2>' . sprintf($this->getLang('totalfound'), $found) . '</h2>';
        else
            echo '<h2>' . sprintf($this->getLang('totalbooksfound'), $found). '</h2>';

        foreach($results as $row) {

            /** @var Elastica\Result $row */
            $page = $row->getSource()['book_name'];
            $page_src = $row->getSource()['citekey'];
           // $genre =  $row->getSource()['genre'];


            // get highlighted  for heading (field1 or field2)
            if($type=='book') $titlefield = $this->getConf('field1');
            else $titlefield = $this->getConf('field2');

            $title = str_replace(
                ['ELASTICSEARCH_MARKER_IN', 'ELASTICSEARCH_MARKER_OUT'],
                ['<strong class="search_hit">', '</strong>'],
                hsc(join(' … ', (array) $row->getHighlights()[$titlefield]))
            );

            if(!$title) $title = hsc($row->getSource()[$titlefield]);
            if(!$title) $title = hsc(p_get_first_heading($page));
            if(!$title) $title = hsc($page);

            // get highlighted  for content (field3)
            $content = str_replace(
                ['ELASTICSEARCH_MARKER_IN', 'ELASTICSEARCH_MARKER_OUT'],
                ['<strong class="search_hit">', '</strong>'],
                hsc(join(' … ', (array) $row->getHighlights()[$this->getConf('field3')]))
            );

            echo '<dt>';
            echo '<a href="'.wl($page_src).'" class="wikilink1" title="'.hsc($page).'">';
            echo $title;
            echo '</a>';
            echo '</dt>';


            echo '<dd class="content">';
            echo $content;
            echo '</dd>';

        }
        echo '</dl>';

        return (bool) $found;
    }

    /**
     * @param ResultSet $result
     */
    protected function print_pagination($result) {
        global $INPUT;
        global $QUERY;

        $all   = $result->getTotalHits();
        $pages = ceil($all / $this->getConf('perpage'));
        $cur   = $INPUT->int('p', 1, true);

        if($pages < 2) return;

        // which pages to show
        $toshow = [1, 2, $cur, $pages, $pages - 1];
        if($cur - 1 > 1) $toshow[] = $cur - 1;
        if($cur + 1 < $pages) $toshow[] = $cur + 1;
        $toshow = array_unique($toshow);
        // fill up to seven, if possible
        if(count($toshow) < 7) {
            if($cur < 4) {
                if($cur + 2 < $pages && count($toshow) < 7) $toshow[] = $cur + 2;
                if($cur + 3 < $pages && count($toshow) < 7) $toshow[] = $cur + 3;
                if($cur + 4 < $pages && count($toshow) < 7) $toshow[] = $cur + 4;
            } else {
                if($cur - 2 > 1 && count($toshow) < 7) $toshow[] = $cur - 2;
                if($cur - 3 > 1 && count($toshow) < 7) $toshow[] = $cur - 3;
                if($cur - 4 > 1 && count($toshow) < 7) $toshow[] = $cur - 4;
            }
        }
        sort($toshow);
        $showlen = count($toshow);

        echo '<ul class="elastic_pagination">';
        if($cur > 1) {
            echo '<li class="prev">';
            echo '<a href="' . wl('', http_build_query(['q' => $QUERY, 'do' => 'search', 'ns' => $INPUT->arr('ns'), 'min' => $INPUT->arr('min'), 'p' => ($cur-1)])) . '">';
            echo '«';
            echo '</a>';
            echo '</li>';
        }

        for($i = 0; $i < $showlen; $i++) {
            if($toshow[$i] == $cur) {
                echo '<li class="cur">' . $toshow[$i] . '</li>';
            } else {
                echo '<li>';
                echo '<a href="' . wl('', http_build_query(['q' => $QUERY, 'do' => 'search', 'ns' => $INPUT->arr('ns'), 'min' => $INPUT->arr('min'), 'p' => $toshow[$i]])) . '">';
                echo $toshow[$i];
                echo '</a>';
                echo '</li>';
            }

            // show seperator when a jump follows
            if(isset($toshow[$i + 1]) && $toshow[$i + 1] - $toshow[$i] > 1) {
                echo '<li class="sep">…</li>';
            }
        }

        if($cur < $pages) {
            echo '<li class="next">';
            echo '<a href="' . wl('', http_build_query(['q' => $QUERY, 'do' => 'search', 'ns' => $INPUT->arr('ns'), 'min' => $INPUT->arr('min'), 'p' => ($cur+1)])) . '">';
            echo '»';
            echo '</a>';
            echo '</li>';
        }

        echo '</ul>';
    }

}
