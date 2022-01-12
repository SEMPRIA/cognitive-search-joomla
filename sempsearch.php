<?php
/*
*
* Search plugin for using the cognitive search engine SEMPRIA-Search.
*
* @author    see sempsearch.xml
* @copyright see sempsearch.xml
*
*/

use Joomla\CMS\Uri\Uri;

class PlgSearchSempSearch extends JPlugin {

  protected static $src_path_search = 'sempria-search';

  protected static $src_path_doc = 'document'; //only fallback

  protected static $semp_out_format = 'opensearch';

  function query($data) {
    $arr = $data;
    $arr['pw'] = $this->params->get('api_pw');
    $arr['corpus'] = $this->params->get('corpus');
    $arr['oformat'] = self::$semp_out_format;
    $base_url = $this->params->get('api_base_url');
    $u = $base_url.'?'.http_build_query($arr);
    $asw = file_get_contents($u);
    return $asw;
  }

  public function __construct(& $subject, $config) {
    parent::__construct($subject, $config);
    //$this->loadLanguage();
  }

/* begin of additional configuration: no stop words and minimum search word length = 1 (instead of 3) */
  public static function setIgnoredSearchWords()
  {
    return [];
  }
  protected $app;
  function onContentSearchAreas() {

      $this->app->getLanguage()->setLowerLimitSearchWordCallback(
        function()
        {
        return 1;
       });
      $this->app->getLanguage()->setIgnoredSearchWordsCallback(array(__CLASS__, 'setIgnoredSearchWords'));

/* end of additional configuration */

    //$asw = $this->query(['sentence' => 'arm']);
    static $areas = array(
      'sempsearch' => 'SempSearch'
    );
    return $areas;
  }

  /*
  * Open Search Entry to search result for SRP page
  */
  function ose2sr($ose, $n) {
    $cnt = (String) $ose->content;
    $htdoc = new DomDocument();
    //$htdoc->loadHTML('<html encoding="UTF-8">' . $cnt . '</html>');
    //$htdoc->loadHTML($cnt);
    $htdoc->loadHTML('<?xml encoding="UTF-8">' . $cnt);
    $htdoc->encoding = 'UTF-8';
    $txt = "";
    $score = "";
    $rnk = "";

    $n = 0;
    $lnk = '';
    $bd = $htdoc->getElementsByTagName('body')[0];
    foreach($bd->childNodes as $ch) {
      $cl = $ch->getAttribute('class');
      switch($cl) {
        case 'semser':
          foreach($ch->childNodes as $sch) {
            if ($sch->nodeType == XML_TEXT_NODE) {
              $nxt = $sch->nextSibling;
              if (!$nxt || $nxt->nodeName != 'a') {
                $t = $htdoc->saveHTML($sch);
                if (strpos($t, '; ') === 0) {
                  $t = substr($t, 2);
                }
                $txt .= $t;
              }
              continue;
            }
            switch ($sch->getAttribute('class')) {
              case 'rank':
                $rnk = $htdoc->saveHTML($sch);
              break;
              case 'score':
                $score = $htdoc->saveHTML($sch);
              break;
              default:
                if ($sch->nodeName != 'a') {
                  $txt .= $htdoc->saveHTML($sch);
                }
            }
          }
        break;
        case 'semboxes':
          foreach($ch->childNodes as $box) {
            $bcl = $box->getAttribute('class');
            switch ($box->getAttribute('class')) {
              case 'semkeytopics':
                foreach($box->getElementsByTagName('a') as $a) {
                  $href = $a->getAttribute('href');
                  $s_uri = $this->tr_search($href);
                  $a->setAttribute('href', $s_uri);
                }
              break;
              case 'semsim':
                foreach($box->getElementsByTagName('a') as $a) {
                  $href = $a->getAttribute('href');
                  $a->setAttribute('href', $this->tr_doc_url($href));
                }
                
              break;
            }
          }
          
          $txt .= $htdoc->saveHTML($ch);
      }
      switch($ch->nodeName) {
        case 'p':
          break;
        case 'div':
          break;
        default:
          break;
      }

    }
    $fst_a = $bd->getElementsByTagName('a')[0];
    $lnk = $this->tr_doc_url($fst_a->getAttribute('href'));
    $title = $fst_a->textContent;

    return (object) array(
      'href' => $lnk,
      'title' => '['.$score.'] '.$title,
      //'section' => implode(' | ', $topics), //(mis)using that field for keywords
      'section' => '',
      'created' => '',
      //'text' => '<pre>'.$p->asXML().'</pre>',
      //'text' => '<pre>'.$txt.'</pre>',
      'text' => $txt,
      //'browsernav' => $n,
      'browsernav' => '1',
      'sem_res' => $txt
    );
  }

  function tr_doc_url($url) {
    $doc_path = $this->params->get('doc_path');
    if (!empty($doc_path)){
      $src_doc_path = $this->params->get('api_doc_path');
      $src_doc_path = $src_doc_path ?: self::$src_path_doc;
      return str_replace($src_doc_path, $doc_path, "".$url, $n);

    }
  }
  
  // translate the orig query params to the frontend query params
  function trqp($p) {
    $local_qp = [
      'searchword' => $p['sentence'],
    ];
    
    $kt = $p['keytopic'];
    if ($kt) {
      $local_qp['keytopic'] = $p['keytopic'];
    }


    return $local_qp;
  }

  // translate search url
  function tr_search($u) {
    $url = parse_url($u);
    $q = $url['query'];
    parse_str($q, $prms);
    if ($this->params->get('srp_semp')) {
      $ret_u = $u;
      if (!isset($url['host'])) {
        $base = $this->params->get('api_base_url');
        $ret_u = str_replace('oformat='.self::$semp_out_format, '', $u);
        $ret_u = str_replace(self::$src_path_search, $base, $ret_u);
      }
      return $ret_u;
    }
    $nqd = $this->trqp($prms);
    $c_url = parse_url($this->cur_uri);
    $n_uri = $c_url['path'].'?'.http_build_query($nqd);
    return $n_uri;
  }

  function onContentSearch($text, $phrase='', $ordering='', $areas=null) {
    if (strlen($text) < 2) {
      return [];
    }
    $kt = JFactory::getApplication()->input->getString('keytopic');
    $this->cur_uri = Uri::getInstance();
    $asw = $this->query(['sentence' => $text, 'keytopic' => $kt]);
    $x = new SimpleXmlElement($asw, LIBXML_NOCDATA);
    //$x = new SimpleXmlElement($asw);
    $list = [];
    $n = 1;
    foreach($x->entry as $entry) {
      $list[$n] = $this->ose2sr($entry, $n);
      $n++;
    }
    $lF = $x->xpath('opensearch:languageFeedback');
    if ($lF) {
      echo $lF[0];
      echo '<br/>';
    }
    $lF = $x->xpath('opensearch:Facets');
    if ($lF) {
      echo $lF[0];
      echo '<br/>';
    }
    if ($kt != "") {
      echo 'Thema: ';
      echo  $kt;
      echo '<br/>';
    }
    //$nUrl = $x->link['href'];
    //$nLocalUrl = $this->tr_search($nUrl);
    return $list;
  }
}

?>
