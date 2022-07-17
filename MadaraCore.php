<?php

namespace App;
use Symfony\Component\DomCrawler\Crawler;

class MadaraCore extends CrawlerCore {
    public $prefix_url;
    public $list_url;


    function list($page){
       $html = $this->bypass(sprintf($this->list_url, $page));

       return array_filter((new Crawler($html))->filter(".page-listing-item .page-item-detail .item-thumb a")->each(function (Crawler $node){
           return $this->prefix_url . $node->attr("href");
       }));
    }

}
