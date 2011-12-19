<?php
 /**
  * dw2Pdf Plugin: Conversion from dokuwiki content to pdf.
  *
  * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
  * @author     Luigi Micco <l.micco@tiscali.it>
  * @author     Andreas Gohr <andi@splitbrain.org>
  */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

class action_plugin_dw2pdf extends DokuWiki_Action_Plugin {

    /**
     * Register the events
     */
    function register(&$controller) {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'convert',array());
    }

    function convert(&$event, $param) {
        global $ACT;
        global $REV;
        global $ID;
        global $conf;

        // our event?
        if (( $ACT != 'export_pdfbook' ) && ( $ACT != 'export_pdf' )) return false;

        // check user's rights
        if ( auth_quickaclcheck($ID) < AUTH_READ ) return false;

        // it's ours, no one else's
        $event->preventDefault();

        // one or multiple pages?
        $list = array();
        if ( $ACT == 'export_pdf' ) {
            $list[0] = $ID;
        } elseif (isset($_COOKIE['list-pagelist'])) {
            $list = explode("|", $_COOKIE['list-pagelist']);
        }

        $tpl = $this->select_template();

        // prepare cache
        $cache = new cache(join(',',$list).$REV.$tpl,'.dw2.pdf');
        $depends['files']   = array_map('wikiFN',$list);
        $depends['files'][] = __FILE__;
        $depends['files'][] = dirname(__FILE__).'/renderer.php';
        $depends['files'][] = dirname(__FILE__).'/mpdf/mpdf.php';

        // hard work only when no cache available
        if(!$this->getConf('usecache') || !$cache->useCache($depends)){
            // initialize PDF library
            require_once(dirname(__FILE__)."/DokuPDF.class.php");
            $mpdf = new DokuPDF();

            // let mpdf fix local links
            $self = parse_url(DOKU_URL);
            $url  = $self['scheme'].'://'.$self['host'];
            if($self['port']) $url .= ':'.$port;
            $mpdf->setBasePath($url);

            // Set the title
            $title = $_GET['pdfbook_title'];
            if(!$title) $title = p_get_first_heading($ID);
            $mpdf->SetTitle($title);

            // some default settings
            $mpdf->mirrorMargins = 1;
            $mpdf->useOddEven    = 1;
            $mpdf->setAutoTopMargin = 'stretch';
            $mpdf->setAutoBottomMargin = 'stretch';

            // load the template
            $template = $this->load_template($tpl, $title);

            // prepare HTML header styles
            $html  = '<html><head>';
            $html .= '<style>';
            $html .= file_get_contents(DOKU_INC.'lib/styles/screen.css');
            $html .= file_get_contents(DOKU_INC.'lib/styles/print.css');
            $html .= file_get_contents(DOKU_PLUGIN.'dw2pdf/conf/style.css');
            $html .= @file_get_contents(DOKU_PLUGIN.'dw2pdf/conf/style.local.css');
            $html .= '@page { size:auto; '.$template['page'].'}';
            $html .= '@page :first {'.$template['first'].'}';
            $html .= '@page :last {'.$template['last'].'}';
            $html .= $template['css'];
            $html .= '</style>';
            $html .= '</head><body>';
            $html .= $template['html'];

            // loop over all pages
            $cnt = count($list);
            for($n=0; $n<$cnt; $n++){
                $page = $list[$n];

                $html .= p_cached_output(wikiFN($page,$REV),'dw2pdf',$page);
                $html .= $template['cite'];
                if ($n < ($cnt - 1)){
                    $html .= '<pagebreak />';
                }
            }

            $this->arrangeHtml($html, $this->getConf("norender"));
            $mpdf->WriteHTML($html);

            // write to cache file
            $mpdf->Output($cache->cache, 'F');
        }

        // deliver the file
        header('Content-Type: application/pdf');
        header('Expires: '.gmdate("D, d M Y H:i:s", time()+max($conf['cachetime'], 3600)).' GMT');
        header('Cache-Control: public, proxy-revalidate, no-transform, max-age='.max($conf['cachetime'], 3600));
        header('Pragma: public');
        http_conditionalRequest(filemtime($cache->cache));

        if($this->getConf('output') == 'file'){
            header('Content-Disposition: attachment; filename="'.rawurlencode($title).'.pdf";');
        }else{
            header('Content-Disposition: inline; filename="'.rawurlencode($title).'.pdf";');
        }

        if (http_sendfile($cache->cache)) exit;

        $fp = @fopen($cache->cache,"rb");
        if($fp){
            http_rangeRequest($fp,filesize($cache->cache),'application/pdf');
        }else{
            header("HTTP/1.0 500 Internal Server Error");
            print "Could not read file - bad permissions?";
        }
        exit();
    }

    /**
     * Choose the correct template
     */
    protected function select_template(){
        $tpl;
        if(isset($_REQUEST['tpl'])){
            $tpl = trim(preg_replace('/[^A-Za-z0-9_\-]+/','',$_REQUEST['tpl']));
        }
        if(!$tpl) $tpl = $this->getConf('template');
        if(!$tpl) $tpl = 'default';
        if(!is_dir(DOKU_PLUGIN.'dw2pdf/tpl/'.$tpl)) $tpl = 'default';

        return $tpl;
    }

    /**
     * Load the various template files and prepare the HTML/CSS for insertion
     */
    protected function load_template($tpl, $title){
        global $ID;
        global $REV;
        global $conf;

        // this is what we'll return
        $output = array(
            'html'  => '',
            'css'   => '',
            'page'  => '',
            'first' => '',
            'last'  => '',
            'cite'  => '',
            'oe'    => 0
        );

        // prepare header/footer elements
        $html = '';
        foreach(array('header','footer') as $t){
            foreach(array('','_odd','_even','_first','_last') as $h){
                if(file_exists(DOKU_PLUGIN.'dw2pdf/tpl/'.$tpl.'/'.$t.$h.'.html')){
                    $html .= '<htmlpage'.$t.' name="'.$t.$h.'">'.DOKU_LF;
                    $html .= file_get_contents(DOKU_PLUGIN.'dw2pdf/tpl/'.$tpl.'/'.$t.$h.'.html').DOKU_LF;
                    $html .= '</htmlpage'.$t.'>'.DOKU_LF;

                    // register the needed pseudo CSS
                    if($h == '_last'){
                        $output['last'] .= $t.': html_'.$t.$h.';'.DOKU_LF;
                    }elseif($h == '_first'){
                        $output['first'] .= $t.': html_'.$t.$h.';'.DOKU_LF;
                    }elseif($h == '_even'){
                        $output['page'] .= 'even-'.$t.'-name: html_'.$t.$h.';'.DOKU_LF;
                    }elseif($h == '_odd'){
                        $output['page'] .= 'odd-'.$t.'-name: html_'.$t.$h.';'.DOKU_LF;
                    }else{
                        $output['page'] .= $t.': html_'.$t.$h.';'.DOKU_LF;
                    }
                }
            }
        }

        // prepare replacements
        $replace = array(
                '@ID@'      => $ID,
                '@PAGE@'    => '{PAGENO}',
                '@PAGES@'   => '{nb}',
                '@TITLE@'   => hsc($title),
                '@WIKI@'    => $conf['title'],
                '@WIKIURL@' => DOKU_URL,
                '@UPDATE@'  => dformat(filemtime(wikiFN($ID,$REV))),
                '@PAGEURL@' => wl($ID,($REV)?array('rev'=>$REV):false, true, "&"),
                '@DATE@'    => dformat(time()),
                '@BASE@'    => DOKU_BASE,
                '@TPLBASE@' => DOKU_PLUGIN.'dw2pdf/tpl/'.$tpl.'/',
        );

        // set HTML element
        $output['html'] = str_replace(array_keys($replace), array_values($replace), $html);

        // citation box
        if(file_exists(DOKU_PLUGIN.'dw2pdf/tpl/'.$tpl.'/citation.html')){
            $output['cite'] = file_get_contents(DOKU_PLUGIN.'dw2pdf/tpl/'.$tpl.'/citation.html');
            $output['cite'] = str_replace(array_keys($replace), array_values($replace), $output['cite']);
        }

        // set custom styles
        if(file_exists(DOKU_PLUGIN.'dw2pdf/tpl/'.$tpl.'/style.css')){
            $output['css'] = file_get_contents(DOKU_PLUGIN.'dw2pdf/tpl/'.$tpl.'/style.css');
        }

        return $output;
    }

    /**
     * Fix up the HTML a bit
     *
     * FIXME This is far from perfect and most of it should be moved to
     * our own renderer instead of modifying the HTML at all.
     */
    protected function arrangeHtml(&$html, $norendertags = '' ) {

        // insert a pagebreak for support of WRAP and PAGEBREAK plugins
        $html = str_replace('<br style="page-break-after:always;">','<pagebreak />',$html);
        $html = str_replace('<div class="wrap_pagebreak"></div>','<pagebreak />',$html);
        $html = str_replace('<span class="wrap_pagebreak"></span>','<pagebreak />',$html);

    }


    /**
     * Strip unwanted tags
     *
     * @fixme could this be done by strip_tags?
     * @author Jared Ong
     */
    protected function strip_only(&$str, $tags) {
        if(!is_array($tags)) {
            $tags = (strpos($str, '>') !== false ? explode('>', str_replace('<', '', $tags)) : array($tags));
            if(end($tags) == '') array_pop($tags);
        }
        foreach($tags as $tag) $str = preg_replace('#</?'.$tag.'[^>]*>#is', '', $str);
    }
}
