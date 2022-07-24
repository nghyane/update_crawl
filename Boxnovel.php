<?php

namespace App\Crawler;

use App\MadaraCore;
use Symfony\Component\DomCrawler\Crawler;

class Boxnovel extends MadaraCore
{
    public $list_url = "https://boxnovel.com/novel/page/%s/";
    public $referer = "https://boxnovel.com/";
    public $proxy = false;
    public $chapter_type = 'text';

    function info($url)
    {
        $html = $this->minifier($this->curl($url));
        $crawler = new Crawler($html);

        $data['name'] = $crawler->filter(".post-title h1")->text();

        if (!$data['name']) {
            return [];
        }

        $post_content = $crawler->filter('.post-content_item')->each(function (Crawler $node) {
            return $node->outerHtml();
        });

        foreach ($post_content as $item) {
            if (strpos($item, 'Alternative') !== false) {
                $data['other_name'] = (new Crawler($item))->filter(".summary-content")->text();
            }

            if (strpos($item, 'Status') !== false) {
                $data['status'] = (new Crawler($item))->filter(".summary-content")->text();
                $data['status'] = ($data['status'] === 'OnGoing') ? 'on-going' : 'completed';
            }
        }

        $data['cover'] = $crawler->filter(".summary_image img")->attr('data-src');

        $crawler->filter(".description-summary .summary__content a")->each(function (Crawler $crawler) {
            foreach ($crawler as $node) {
                $node->parentNode->removeChild($node);
            }
        });

        $crawler->filter(".description-summary .summary__content p")->each(function (Crawler $crawler) {
            $outerHTML = $crawler->outerHtml();
            if(strpos($outerHTML, 'BOXNOVEL') !== false  || strpos($outerHTML, 'Status in COO') !== false){
                foreach ($crawler as $node) {
                    $node->parentNode->removeChild($node);
                }
            }
        });

        $data['description'] = $this->strip_word_html($crawler->filter(".description-summary .summary__content")->outerHtml());

        $data['taxonomy']['wp-manga-genre'] = $crawler->filter(".genres-content a")->each(function (Crawler $node) {
            return trim($node->text());
        });

        $data['taxonomy']['wp-manga-author'] = $crawler->filter(".author-content a")->each(function (Crawler $node) {
            return trim($node->text());
        });

        $taxonomys = array_filter($crawler->filter(".post-content_item")->each(function (Crawler $node) {
            $text = $node->text();
            if (strpos($text, 'Type') !== false) {
                $data['wp-manga-type'] = $node->filter(".summary-content")->text();
            }

            if (strpos($text, 'Tag(s)') !== false) {
                $data['wp-manga-tag'] = $node->filter(".summary-content a")->each(function (Crawler $node) {
                    return trim($node->text());
                });
            }

            if (strpos($text, 'Release') !== false) {
                $data['wp-manga-release'] = $node->filter(".summary-content a")->each(function (Crawler $node) {
                    return trim($node->text());
                });
            }

            return $data ?? null;
        }));

        foreach ($taxonomys as $value) {
            foreach ($value as $key => $value2) {
                $taxonomy[$key] = $value2;
            }
        }

        if (!empty($taxonomy['wp-manga-tag'])) {
            $data['taxonomy']['wp-manga-tag'] = $taxonomy['wp-manga-tag'];
        }

        if (!empty($taxonomy['wp-manga-release'])) {
            $data['taxonomy']['wp-manga-release'] = $taxonomy['wp-manga-release'];
        }

        $data['type'] = $taxonomy['wp-manga-type'] ?? 'Light Novel';

        $htmlPost = $this->post($url . "ajax/chapters/");

        $data['list_chapter'] = array_reverse(array_filter((new Crawler($htmlPost))->filter(".listing-chapters_wrap li a")->each(function (Crawler $node) {
            preg_match('/(chapter|chương|chap)(.[\d.]+)(.*)/is', $node->text(), $output_array);

            if (!$output_array[2]) {
                return [];
            }

            $name_extend = trim($output_array[3] ?? null);
            if ($name_extend) {
                $name_extend = ltrim($name_extend, '-');
                $name_extend = ltrim($name_extend, ':');
            }

            return [
                'name_extend' => $name_extend,
                'name' => "Chapter " . trim($output_array[2]),
                'url' => $node->attr('href')
            ];
        })));

        return $data;
    }

    function content($url){
        $html = $this->curl($url);
        $crawler = new Crawler($html);

        $crawler->filter("h2.text-center")->each(function (Crawler $crawler) {
            foreach ($crawler as $node) {
                $node->parentNode->removeChild($node);
            }
        });

        $crawler->filter("h3.dib")->each(function (Crawler $crawler) {
            foreach ($crawler as $node) {
                $node->parentNode->removeChild($node);
            }
        });

        $chapter['type'] = 'text';
        $chapter['content'] = $crawler->filter(".reading-content .text-left")->outerHtml();
        $chapter['content'] = $this->strip_word_html($chapter['content']);


        return $chapter;
    }
}
