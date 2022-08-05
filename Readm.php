<?php

namespace App\Crawler;

use App\CrawlerCore;
use Symfony\Component\DomCrawler\Crawler;

class Readm extends CrawlerCore
{
    public $referer = 'https://www.readm.org/latest-releases';
    public $base_url = "https://www.readm.org";

    function list($page)
    {
        $url = "https://www.readm.org/latest-releases/$page";
        $html = $this->curl($url);

        return (new Crawler($html))->filter("ul.latest-updates .truncate a")->each(function (Crawler $node) {
            return $this->base_url . $node->attr("href");
        });
    }


    function info($url)
    {
        $html = $this->curl($url);
        $crawler = new Crawler($html);


        $data['name'] = trim($crawler->filter(".page-title")->text());
        if ($crawler->filter(".series-profile-thumb")->count() > 0) {
            $data['cover'] = $crawler->filter(".series-profile-thumb")->attr("src");
            if (strpos($data['cover'], 'http') === false) {
                $data['cover'] = $this->base_url . $data['cover'];
            }
        }

        if ($crawler->filter("#content .sub-title")->count() > 0) {
            $data['other_name'] = trim($crawler->filter("#content .sub-title")->text());
        }

        if ($crawler->filter(".series-summary-wrapper p")->count() > 0) {
            $data['description'] = $crawler->filter(".series-summary-wrapper p")->eq(1)->text();
        }

        $data['type'] = $this->strip_word_html(explode_by("Type</div>", "</div>", $html));

        $data['taxonomy']['wp-manga-genre'] = $crawler->filter(".series-summary-wrapper a")->each(function (Crawler $node) {
            return trim($node->text());
        });

        $data['status'] = $crawler->filter(".series-genres")->count() > 0 ? 'on-going' : 'completed';

        $data['taxonomy']['wp-manga-author'] = $crawler->filter("#first_episode a")->each(function (Crawler $node) {
            return trim($node->text());
        });

        $data['list_chapter'] = array_reverse(array_filter(
            $crawler->filter(".episodes-list .table .table-episodes-title a")->each(function (Crawler $node) {
                return [
                    'name' => $node->text(),
                    'url' => $this->base_url . $node->attr('href')
                ];
            })
        ));

        return $data;
    }

    function content($url){
        $html  = $this->curl($url);
        $crawler = new Crawler($html);

        return [
            'type' => 'image',
            'content' => $crawler->filter(".chapter a img, .chapter img")->each(function (Crawler $node){
                $url = $node->attr("src");

                if(strpos($url, 'http') === false){
                    $url = $this->base_url . $url;
                }

                return $url;
            })
        ];
    }

    function curl($url)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_DNS_CACHE_TIMEOUT, 2);

        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        curl_setopt($curl, CURLOPT_DNS_USE_GLOBAL_CACHE, false);
        curl_setopt($curl, CURLOPT_RESOLVE, [
            'www.readm.org:443:45.138.37.37',
            'readm.org:443:45.138.37.37',
        ]);

        $res = (curl_exec($curl));

        curl_close($curl);

        return $res;
    }
}
