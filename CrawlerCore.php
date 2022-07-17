<?php

namespace App;

use App\Models\Chapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Post;
use App\Models\Term;
use App\Models\Taxonomy;
use Illuminate\Support\Facades\Http;

class CrawlerCore
{
    public $proxy = true;
    public $referer = 'https://google.com/';
    public $headers = [];
    public $crawler;
    public $chapter_type = 'manga'; // text

    function crawler($sources)
    {
        if (!$this->crawler) {
            $class = '\\App\\Crawler\\' . $sources;
            if (!class_exists($class)) {
                exit();
            }

            return new $class;
        }

        return $this->crawler;
    }

    function curl($url)
    {

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_URL, $url);

        $headers = array(
            "referer: $this->referer",
            "user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.45 Safari/537.36",
        );

        $headers = array_merge($headers, $this->headers);

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);


        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);


        $resp = curl_exec($curl);

        if (!curl_error($curl)) {
            echo curl_error($curl);
        }

        curl_close($curl);
        return $resp;
    }

    function minifier($code)
    {
        $search = array(

            // Remove whitespaces after tags
            '/\>[^\S ]+/s',

            // Remove whitespaces before tags
            '/[^\S ]+\</s',

            // Remove multiple whitespace sequences
            '/(\s)+/s',

            // Removes comments
            '/<!--(.|\s)*?-->/'
        );
        $replace = array('>', '<', '\\1');
        return preg_replace($search, $replace, $code);
    }

    function strip_word_html($text, $allowed_tags = '<ul><li><b><i><sup><sub><em><strong><u><br><br/><br /><p><h2><h3><h4><h5><h6>')
    {
        mb_regex_encoding('UTF-8');
        //replace MS special characters first
        $search = array('/&lsquo;/u', '/&rsquo;/u', '/&ldquo;/u', '/&rdquo;/u', '/&mdash;/u');
        $replace = array('\'', '\'', '"', '"', '-');
        $text = preg_replace($search, $replace, $text);
        //make sure _all_ html entities are converted to the plain ascii equivalents - it appears
        //in some MS headers, some html entities are encoded and some aren't
        //$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        //try to strip out any C style comments first, since these, embedded in html comments, seem to
        //prevent strip_tags from removing html comments (MS Word introduced combination)
        if(mb_stripos($text, '/*') !== FALSE){
            $text = mb_eregi_replace('#/\*.*?\*/#s', '', $text, 'm');
        }
        //introduce a space into any arithmetic expressions that could be caught by strip_tags so that they won't be
        //'<1' becomes '< 1'(note: somewhat application specific)
        $text = preg_replace(array('/<([0-9]+)/'), array('< $1'), $text);
        $text = strip_tags($text, $allowed_tags);
        //eliminate extraneous whitespace from start and end of line, or anywhere there are two or more spaces, convert it to one
        $text = preg_replace(array('/^\s\s+/', '/\s\s+$/', '/\s\s+/u'), array('', '', ' '), $text);
        //strip out inline css and simplify style tags
        $search = array('#<(strong|b)[^>]*>(.*?)</(strong|b)>#isu', '#<(em|i)[^>]*>(.*?)</(em|i)>#isu', '#<u[^>]*>(.*?)</u>#isu');
        $replace = array('<b>$2</b>', '<i>$2</i>', '<u>$1</u>');
        $text = preg_replace($search, $replace, $text);
        //on some of the ?newer MS Word exports, where you get conditionals of the form 'if gte mso 9', etc., it appears
        //that whatever is in one of the html comments prevents strip_tags from eradicating the html comment that contains
        //some MS Style Definitions - this last bit gets rid of any leftover comments */
        $num_matches = preg_match_all("/\<!--/u", $text, $matches);
        if($num_matches){
            $text = preg_replace('/\<!--(.)*--\>/isu', '', $text);
        }
        $text = preg_replace('/(<[^>]+) style=".*?"/i', '$1', $text);
        return $text;
    }

    function post($url, $data = false)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $headers = array(
            "Content-Type: application/x-www-form-urlencoded",
        );

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        if($data){
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }

        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        $resp = curl_exec($curl);
        curl_close($curl);

        return $resp;
    }

    function bypass($url){
        return $this->curl($url);
    }
}
