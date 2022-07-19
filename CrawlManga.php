<?php

namespace App\Console\Commands;

use App\CrawlerCore;
use App\Models\Chapter;
use App\Models\ChapterData;
use App\Models\Post;
use App\Models\Taxonomy;
use App\Models\Term;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CrawlManga extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawl:manga {source} {--cron} {--only_update} {--url=} {--max_page=1} {--min_page=1} {--save_image=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Save data mangas';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */

    public $info;
    public $referer = "https://google.com";

    public function handle()
    {
        set_time_limit(0);

        $max_page = $this->option('max_page');
        $min_page = $this->option('min_page');
        $url = $this->option('url');
        $save_image = $this->option('save_image');
        $source = $this->argument('source');

        if (!$this->option('cron')) {
            $type = $this->choice(
                __('Chose type leech'),
                ['auto', 'link'],
                'link'
            );

            if ($type == 'auto') {
                $this->comment('Crawl will run from page max to min');

                $max_page = $this->ask(__("Max page"), 1);
                $min_page = $this->ask(__("Min page"), 1);
            }

            if ($type == 'link') {
                $url = $this->ask(__("Input manga url"));
            }
        }

        if ($url) {
            try {
                $this->getDataFromUrl($url, $source, $save_image);
            } catch (\Exception $e) {
                $this->warn($url);

                $this->error($e);
            }
            return 0;
        }

        while ($max_page >= $min_page) {
            $this->output->writeln(__("Tool running..."));

            $list = ((new CrawlerCore())->crawler($source))->list($max_page);

            foreach ($list as $url) {
                try {
                    $this->getDataFromUrl($url, $source, $save_image);
                } catch (\Exception $e) {
                    $this->warn($url);

                    $this->error($e);
                }
            }

            $max_page--;
        }

        return 0;
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    function getDataFromUrl($url, $sources, $save_image): bool
    {
        $is_new = false;
        if (empty($url) || filter_var(filter_var($url, FILTER_SANITIZE_URL), FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $this->info = ((new CrawlerCore())->crawler($sources)->info($url));
        $this->info['slug'] = Str::slug($this->info['name']);

        $post = Post::query()->where('post_title', 'LIKE', $this->info['name'])->first('ID');

        if (!$post) {
            if ($this->option('only_update')) {
                $this->output->writeln("Truyện chưa tồn tại: " . $this->info['name']);
                return true;
            }

            $post = Post::create([
                'post_title' => $this->info['name'],
                'post_content' => $this->info['description'] ?? '',
                'post_status' => 'publish',
                'comment_status' => 'open',
                'ping_status' => 'closed',
                'post_name' => $this->info['slug'],
                'guid' => Options()['siteurl'] . "?post_type=wp-manga&slug=" . $this->info['slug'],
                'post_type' => 'wp-manga',

            ]);
        }

        $this->info(__("Saved: -- :name", ['name' => $this->info['name']]));
        $this->comment(" -- start scanning details:");

        $meta = [
            '_manga_avarage_reviews' => 0,
            'manga_unique_id' => 'manga_' . md5($post->ID),
            'manga_adult_content' => '',
            '_wp_manga_chapter_type' => (new CrawlerCore())->crawler($sources)->chapter_type,
            'manga_reading_style' => 'default',
            'manga_reading_content_gaps' => 'default',
            'manga_title_badges' => 'no',
            'manga_custom_badge_link_target' => '_self',
            'manga_profile_background' => 'a:6:{s:16:"background-color";s:0:"";s:17:"background-repeat";s:0:"";s:21:"background-attachment";s:0:"";s:19:"background-position";s:0:"";s:15:"background-size";s:0:"";s:16:"background-image";s:0:"";}',
            '_wp_manga_status' => $this->info['status'],
        ];

        // Install https://wordpress.org/plugins/external-url-as-post-featured-image-thumbnail/

        if (!empty($this->info['other_name'])) {
            $meta['_wp_manga_alternative'] = $this->info['other_name'];
        }

        if (!empty($this->info['type'])) {
            $meta['_wp_manga_type'] = $this->info['type'];
        }

        $cover_name = $this->info['slug'] . '.jpg';

        $CrawlerCore = (new CrawlerCore())->crawler($sources);

        if (Storage::disk('cover_uploads')->exists($cover_name)) {
            $data_exist = Storage::disk('cover_uploads')->get($cover_name);
            if (empty($data_exist) || strpos($data_exist, 'html') !== false) {
                Storage::disk('cover_uploads')->put($cover_name, $CrawlerCore->curl($this->info['cover']));
            }
        } else {
            Storage::disk('cover_uploads')->put($cover_name, $CrawlerCore->curl($this->info['cover']));
        }

        $this->info['cover'] = Options()['siteurl'] . '/wp-content/uploads/covers/' . $cover_name;

        $meta['_thumbnail_ext_url'] = $this->info['cover'];

        $post->saveMeta($meta);

        foreach ($this->info['taxonomy'] as $taxonomy => $terms) {
            foreach (array_filter($terms) as $term) {
                $termModel = Term::where('name', $term)->first();
                if (!$termModel) {
                    $termModel = Term::create([
                        'name' => $term, 'slug' => Str::slug($term)
                    ]);
                }

                $taxonomyModel = Taxonomy::where('term_id', $termModel->term_id)->first();

                if (!$taxonomyModel) {
                    $taxonomyModel = Taxonomy::create([
                        'term_id' => $termModel->term_id,
                        'taxonomy' => $taxonomy,
                    ]);

                    $taxonomyModel->term()->associate($termModel);
                }
                $taxonomies = $post->taxonomies();

                try {
                    $taxonomies->term_taxonomy_id = $taxonomyModel->term_taxonomy_id;
                    $taxonomies->object_id = $post->ID;
                    $taxonomies->save($taxonomyModel);
                } catch (\Exception $e) {

                }

            }
        }

        print_r($post->ID);
        $chapters = Chapter::where('post_id', '=', $post->ID)
            ->get(['chapter_name', 'chapter_slug']);

        $check_list = [];

        $chapter_index = ($chapters)->count();

        $this->comment(__(" -- found :x chapters", ['x' => count($this->info['list_chapter'])]));

        foreach ($chapters as $available_chap) {
            $check_list[] = Str::slug($available_chap->chapter_name);
        }

        $check_list = array_flip($check_list);

        foreach ($this->info['list_chapter'] as $chap) {
            $availabled = array_key_exists(Str::slug($chap['name']), $check_list);
            if ($availabled) {
                $this->info(__("Exist - :chap", ['chap' => $chap['name']]));
                continue;
            }

            $deepCheck = Chapter::query()->where('chapter_slug', Str::slug($chap['name']))->where('post_id', '=', $post->ID)->first('chapter_id');
            if ($deepCheck) {
                $this->info(__("Exist - :chap", ['chap' => $chap['name']]));
                continue;
            }

            $chap = $this->getChapFromUrl($chap['url'], $sources, $chap);

            $this->info(__("New - :chap", ['chap' => $chap['name']]));

            $chapter_index++;
            $storage = config('wpsite.storage', 'local');

            DB::beginTransaction();
            // Save chap data
            $chapter_id = Chapter::create([
                'post_id' => $post->ID,
                'chapter_name' => $chap['name'],
                'chapter_slug' => $chap['slug'],
                'chapter_index' => $chapter_index,
                'chapter_name_extend' => $chap['name_extend'] ?? "",
                'storage_in_use' => $storage . '_autoMDR',
                'volume_id' => 0
            ])->id;

            if ((new CrawlerCore())->crawler($sources)->chapter_type !== 'text') {
                if ($save_image) {
                    $this->comment(__(" -- begin save image to :storage", ['storage' => $storage]));
                    $total_img = count($chap['content']);
                }

                foreach ($chap['content'] as $key => $img) {
                    $img_url = $img;

                    if ($save_image) {
                        $CrawlerCore = (new CrawlerCore());
                        $CrawlerCore->referer = $CrawlerCore->crawler($sources)->referer;

                        $img_data = $CrawlerCore->curl($img);

                        if (empty($img_data) || strpos($img_data, 'html') !== false || strpos($img_data, 'error') !== false) {
                            break;
                        }

                        $path = "$post->ID/$chapter_id/" . $key . '.jpg';
                        switch ($storage):
                            case 'local':
                                if (Storage::disk('manga_uploads')->put($path, $img_data)) {
                                    $img_url = asset("mangas/" . $path);
                                }
                                break;
                            case 'remote':
                                $path = trim(config("wpsite.$storage.path"), '/') . '/' . $path;
                                $res = Http::asForm()->post(config("wpsite.$storage.endpoint_url"), ['data' => base64_encode($img_data), 'path' => $path]);
                                if (@json_decode($res->body())) {
                                    $img_url = config("wpsite.$storage.base_url") . '/' . $path;
                                }
                                break;
                            default:
                                exit("Please put your storage config in .env");
                        endswitch;

                        $this->output->writeln("[$key/" . ($total_img - 1) . "] " . $img_url);
                    }

                    $content[] = $img_url;
                }
            } else {
                $content = $chap['content'];
            }

            if (empty($content)) {
                DB::rollBack();
                Chapter::query()->where('chapter_id', $chapter_id)->delete();
                $this->alert("Xoá chap lỗi -- $chapter_id");
            } else {
                if ((new CrawlerCore())->crawler($sources)->chapter_type !== 'text') {
                    ChapterData::query()->create([
                        'chapter_id' => $chapter_id,
                        'storage' => $storage . '_autoMDR',
                        'data' => $this->toMadaraImageContent($content)
                    ]);
                } else {
                    Post::create([
                        'post_content' => $content,
                        'post_title' => $chapter_id . '-' . $chap['slug'],
                        'post_name' => $chapter_id . '-' . $chap['slug'],
                        'post_status' => 'publish',
                        'comment_status' => 'open',
                        'ping_status' => 'closed',
                        'post_parent' => $chapter_id,
                        'guid' => Options()['siteurl'] . "/chapter_text_content/" . $chapter_id . '-' . $chap['slug'],
                        'post_type' => 'chapter_text_content',
                        'wp_manga_search_text' => null,
                    ]);
                }

                Chapter::query()->where('chapter_id', $chapter_id)->update([
                    'chapter_status' => 1
                ]);

                $post->saveMeta(['_latest_update' => time()]);

                Post::query()->where('ID', $post->ID)->update([
                    'post_modified' => Carbon::now(),
                    'post_modified_gmt' => Carbon::now(),
                    'post_content' => $this->info['description'] ?? '',
                ]);

                DB::commit();
            }

        }

        return true;
    }

    function toMadaraImageContent($content)
    {
        foreach ($content as $img) {
            $images[] = [
                'src' => $img,
                'mime' => 'image/jpeg'
            ];
        }

        return json_encode($images, JSON_FORCE_OBJECT);
    }

    function getChapFromUrl($url, $sources, $subdata = null)
    {
        if (filter_var(filter_var($url, FILTER_SANITIZE_URL), FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $data = ((new CrawlerCore())->crawler($sources)->content($url));
        $data['url'] = $url;
        $data['name'] = $data['name'] ?? $subdata['name'];
        $data['slug'] = Str::slug($data['name']);
        $data['name_extend'] = $data['name_extend'] ?? ($subdata['name_extend'] ?? '');

        return $data;
    }
}
