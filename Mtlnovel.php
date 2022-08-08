<?php

namespace App\Crawler;

use App\CrawlerCore;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\DomCrawler\Crawler;

class Mtlnovel extends CrawlerCore
{
    public $proxy = false;
    public $chapter_type = 'text';

    function list($page = 1){
        $html = $this->bypass("https://www.mtlnovel.com/novel-list/?orderby=date&order=desc&status=all&pg=$page&time=" . time());
        $crawler  = new Crawler($html);

        return $crawler->filter('.data-r')->each(function (Crawler $node){
            $node = $node->filter('a')->eq(0);

            return $node->attr('href');
        });
    }

    function info($url){
        $html = $this->bypass($url);
        $crawler  = new Crawler($html);

        $data["name"] = $crawler->filter(".entry-title")->text();
        $data["other_name"] = $crawler->filter("#alt")->text();
        $data["cover"] = $crawler->filter(".nov-head amp-img")->attr("src");
        $crawler->filter(".desc h2, .descr")->each(function (Crawler $crawler){
            foreach ($crawler as $node){
                $node->parentNode->removeChild($node);
            }
        });

        $data["description"] = $this->minifier($crawler->filter(".desc")->outerHtml());

        $data['status'] = trim($crawler->filter("#status a")->text());
        $data['status'] = $data['status'] === 'Ongoing' ? 'on-going' : 'completed';

        $data['taxonomy']['wp-manga-genre'] = $crawler->filter("#currentgen a")->each(function (Crawler $node){
            return trim($node->text());
        });
        $data['taxonomy']['wp-manga-author'] = $crawler->filter("#author a")->each(function (Crawler $node){
            return trim($node->text());
        });
        $data['taxonomy']['wp-manga-tag'] = array_filter($crawler->filter("#tags a")->each(function (Crawler $node){
            $text = trim($node->text());
            if($text === 'See edit history'){
                return null;
            }

            return $text;
        }));

        $data['taxonomy']['author'][] = basename(str_replace('\\', '/', static::class));


        $chapter_list = $this->bypass(trim($crawler->filter(".view-all")->attr('href')) . '?time=' . time());

        $data['list_chapter'] = array_reverse((new Crawler($this->minifier($chapter_list)))->filter(".post-content .ch-link")->each(function (Crawler $node){
            $name = trim($node->filter("strong")->eq(0)->text());

            if($name == '~'){
                $name = $node->text();
            }

            return [
                'name' => $name,
                'name_extend' => trim(str_replace($name, '', $node->text())),
                'url' => $node->attr("href")
            ];
        }));

        return $data;
    }

    function content($url){
        $html = $this->bypass($url);
        $crawler = new Crawler($html);

        $crawler->filter(".inarticle-ads")->each(function (Crawler $crawler){
            foreach ($crawler as $node){
                $node->parentNode->removeChild($node);
            }
        });

        return [
            'type' => 'text',
            'content' => $this
                ->minifier(
                    $crawler->filter(".post-content .par")->outerHtml()
                )
        ];
    }

    function curl($url){
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_DNS_CACHE_TIMEOUT, 2);

        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        curl_setopt($curl, CURLOPT_DNS_USE_GLOBAL_CACHE, false);
        curl_setopt($curl, CURLOPT_RESOLVE, [
            'www.mtlnovel.com:443:206.189.188.116',
            'www.mtlnovel.com:80:206.189.188.116',
            'mtlnovel.com:443:206.189.188.116',
            'mtlnovel.com:80:206.189.188.116',
        ]);

        $res = (curl_exec($curl));

        curl_close($curl);

        return $res;
    }

}
