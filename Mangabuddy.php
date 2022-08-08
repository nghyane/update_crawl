<?php

namespace Crawler;

use Symfony\Component\DomCrawler\Crawler;

class Mangabuddy extends CrawlerCore
{
    public $proxy = true;
    public $referer = "https://mangabuddy.com";
    public $base_url = "https://mangabuddy.com";

    public function int()
    {
        $this->options['headers']['referer'] = $this->referer;
        $this->options['headers']['user-agent'] = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.45 Safari/537.36";
    }

    function list($page)
    {
        $html = $this->bypass("https://mangabuddy.com/latest?page=$page");
        $crawler = new Crawler($html);

        return $crawler->filter(".book-detailed-item .meta .title h3 a")->each(function (Crawler $node) {
            return $node->attr('href');
        });
    }

    function info($url)
    {
        $html = $this->bypass($url);
        $crawler = new Crawler($html);

        $data['name'] = $crawler->filter('.book-details .detail h1')->text();

        $data['cover'] = $crawler->filter(".img-cover img")->attr('data-src');

        $other_name = $crawler->filter('.book-details .detail h2');
        if ($crawler->count() > 0) {
            $data['other_name'] = $other_name->text();
        }

        $crawler->filter('.book-details .detail .meta p')->each(function (Crawler $node) use (&$data) {
            $meta_key = $node->filter('strong')->text();
            if (strpos($meta_key, 'Authors') !== false) {
                $data['taxonomy']['authors'] = $node->filter('a')->each(function (Crawler $node) {
                    return trim(trim(trim($node->text()), ','));
                });
                return;
            }

            if (strpos($meta_key, 'Genres') !== false) {
                $data['taxonomy']['genres'] = $node->filter('a')->each(function (Crawler $node) {
                    return trim(trim(trim($node->text()), ','));
                });
                return;
            }

            if (strpos($meta_key, 'Status') !== false) {
                $data['status'] = trim($node->filter('span')->text());
                $data['status'] = $data['status'] === 'Ongoing' ? 'on-going' : 'completed';
            }
        });


        $description = $crawler->filter(".book-details .summary .content");
        if ($description->count() > 0) {
            $data['description'] = $description->text();
        }

        $bookSlug = explode_by('bookSlug = "', '"', $html);
        $chapList = $this->bypass("https://mangabuddy.com/api/manga/$bookSlug/chapters?source=detail");

        $data['list_chapter'] = (new Crawler($chapList))->filter("#chapter-list li a")->each(function (Crawler $node) {
            return [
                'url' => ltrim($node->attr('href'), '/'),
                'name' => $node->filter('strong')->text()
            ];
        });

        $data['list_chapter'] = array_reverse(array_filter($data['list_chapter']));

        return $data;
    }

    function content($url)
    {
        $html = $this->bypass($url);

        $chapImages = explode_by("chapImages = '", "'", $html);
        $chapImages = explode(',', $chapImages);

        $mainServer = explode_by('mainServer = "', '"', $html);
        if(strpos($mainServer, 'http') === false){
            $mainServer = 'https:' . $mainServer;
        }
        foreach ($chapImages as $key => $image){
            $chapImages[$key] = $mainServer.$image;
        }


        return [
            'type' => 'image',
            'content' => $chapImages
        ];
    }

    function bypass($url)
    {
        if (strpos($url, 'mangabuddy-com.translate.goog') !== false) {
            $url = str_replace('mangabuddy-com.translate.goog', 'mangabuddy-com.b-cdn.net', $url);
        } else if (strpos($url, 'mangabuddy.com') !== false) {
            $url = str_replace('mangabuddy.com', 'mangabuddy-com.b-cdn.net', $url);
            $trigger = "?";

            if (strpos($url, '?') !== false) {
                $trigger = "&";
            }

            $url = $url . $trigger . "_x_tr_sl=vi&_x_tr_tl=en&_x_tr_hl=en&_x_tr_pto=op,wapp";
        }

        return $this->curl($url);
    }
}