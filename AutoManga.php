<?php

namespace Command;

use Config\Config;
use CrawlHelpers;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\EachPromise;
use Models\Manga;
use Models\Model;
use Models\Taxonomy;
use Psr\Http\Message\ResponseInterface;
use Services\Bunny;
use Services\Local;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\PdoStore;

class AutoManga extends CrawlHelpers
{
    use LockableTrait;

    protected static $defaultName = 'auto:manga';

    public $source;
    public $config;
    public $URLS = [];

    public $INFO = [];

    public $store;
    public $factory;

    public $site_url;

    protected function configure(): void
    {
        $this->addArgument('source', InputArgument::OPTIONAL, 'Source');
        $this->addOption('update', null, InputOption::VALUE_OPTIONAL);
        $this->addOption('page', null, InputOption::VALUE_OPTIONAL);
        $this->addOption('link', null, InputOption::VALUE_OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $page = 1;
        $lock = true;
        $max_process = $this->config->max_process_running;
        $current_process = 1;
        while ($current_process <= $max_process) {
            if ($this->lock(md5(__DIR__ . self::$defaultName . $this->source . $current_process))) {
                $lock = false;
                break;
            }
            $current_process++;
        }

        if ($lock) {
            $output->writeln('The command is already running in another process.');
            return Command::SUCCESS;
        }

        while (true) {
            if ($input->getOption('update')) {
                if ($page > $this->config->max_page_update) {
                    $this->release();
                    break;
                }
            }

            try {
                if (!$input->getOption('link')) {
                    $this->URLS = $this->crawler->list($page);
                } else {
                    $this->URLS = [];
                    $helper = $this->getHelper('question');

                    while (true) {

                        $url = $helper->ask($input, $output,
                            new Question("Nhập URL [0: thoát]: ", 0)
                        );
                        if (!$url) {
                            break;
                        }

                        $this->URLS[] = $url;
                    }
                }
            } catch (\Exception $e) {
                $this->URLS = [];
                $output->writeln("<comment>Can't find any manga url, please check campaign or source!</comment>");
                $output->writeln($e);
                break;
            }

            if (!$this->URLS) {
                $output->writeln("<info>Done!</info>");
                break;
            }

            foreach ($this->URLS as $url) {
                $lock = $this->factory->createLock(md5($url), 300);
                if (!$lock->acquire()) {
                    $output->writeln("<comment>[Running]</comment> $url");
                    continue;
                }

                try {
                    $output->writeln("<info>[Begin]</info> $url");

                    $this->INFO = $this->crawler->info($url);
                    if (!$this->INFO) {
                        Model::getDB()->insert('auto_logs', [
                            'title' => $url,
                            'url' => $url,
                            'description' => 'Unable to find data!'
                        ]);
                    }

                    $isNewManga = false;
                    $MID = Manga::getDB()->where('name', $this->INFO['name'])
                        ->getValue('mangas', 'id');

                    $slug = $this->INFO['slug'] ?? slugGenerator($this->INFO['name']);

                    // Manga cover
                    if ($this->config->save_poster) {
                        $path = "covers/$slug.jpg";
                        if (!file_exists($path)) {
                            $imgData = $this->crawler->bypass($this->INFO['cover']);
                            if (!empty($imgData) && $this->isBinary($imgData)) {
                                $this->INFO['cover'] = (new Local())->upload(1, $path);

                                $this->INFO['cover'] = (new Bunny())->upload($imgData, $path);
                            }
                        }

                    }

                    if (!$MID) {
                        if ($this->config->only_update) {
                            continue;
                        }

                        $isNewManga = true;

                        // Save Manga Data
                        $MID = Manga::AddManga(array_filter([
                            'name' => $this->INFO['name'],
                            'slug' => $slug,
                            'other_name' => $this->INFO['other_name'] ?? null,
                            'description' => $this->INFO['description'] ?? null,
                            'released' => $this->INFO['released'] ?? null,
                            'country' => $this->INFO['country'] ?? null,
                            'type' => $this->INFO['type'] ?? null,
                            'views' => $this->INFO['views'] ?? 0,
                            'adult' => $this->INFO['adult'] ?? 0,
                            'status' => $this->INFO['status'] ?? 'on-going',
                            'cover' => $this->INFO['cover'],
                            'last_update' => Manga::getDB()->now(),
                        ]));

                        $output->writeln("<comment>[New Manga]</comment> " . $this->INFO['name']);
                    }

                    if (!is_int($MID)) {
                        continue;
                    }

                    foreach ($this->INFO['taxonomy'] as $taxonomy => $taxonomy_data) {
                        if (!empty($taxonomy_data) && is_array($taxonomy_data)) {
                            $taxonomy_data = array_filter($taxonomy_data);

                            if (!empty($taxonomy_data) && is_array($taxonomy_data)) {
                                $taxonomy_data = array_filter($taxonomy_data);
                                Taxonomy::SetTaxonomy($taxonomy, $taxonomy_data, $MID);
                            }
                        }
                    }

                    $checkList = [];
                    if (!$isNewManga) {
                        $savedChapters = Model::getDB()->where('manga_id', $MID)->get('chapters');

                        foreach ($savedChapters as $chap) {
                            $checkList[] = $chap['slug'];
                        }

                        $checkList = array_flip($checkList);
                    }

                    $c_index = count($checkList) + 1;
                    foreach ($this->INFO['list_chapter'] as $chap) {
                        $chap['slug'] = slugGenerator($chap['name']);
                        if (key_exists($chap['slug'], $checkList)
                            ||
                            Model::getDB()->where('name', $chap['name'])
                                ->where('manga_id', $MID)
                                ->getValue('chapters', 'id')) {
                            $output->writeln('<comment>[Exist]</comment> ' . $this->INFO['name'] . ' - ' . $chap['name']);
                            continue;
                        }


                        Model::getDB()->startTransaction();
                        try {
                            $output->writeln('<info>[New]</info> <comment>' . $this->INFO['name'] . ' - ' . $chap['name'] . '</comment>');

                            $CID = Model::getDB()->insert('chapters', [
                                'name' => $chap['name'],
                                'name_extend' => $chap['name_extend'] ?? null,
                                'slug' => $chap['slug'],
                                'chapter_index' => $c_index++,
                                'manga_id' => $MID,
                                'views' => 0,
                                'hidden' => 1
                            ]);

                            if (!is_int($CID)) {
                                Model::getDB()->rollback();
                                break;
                            }


                            $CTID = Model::getDB()->insert('chapter_data', [
                                'type' => 'leech',
                                'chapter_id' => $CID,
                                'source' => $chap['url'],
                                'content' => $chap['url'],
                                'storage' => $this->source,
                                'storage_name' => 'Server 1',
                                'used' => 1
                            ]);


                            $chapContent = $this->crawler->content($chap['url']);
                            $CTimages = [];
                            $ChapCT = $chapContent['content'];

                            if ($chapContent['type'] == 'image') {
                                $image_count = count($chapContent['content']);
                                $progressBar = new ProgressBar($output->section());
//                                $progressBar->start($image_count);
                                $i = 1;
                                $ServicesUpload = ("\\Services\\" . $this->config->server_image);

                                if (!empty($this->config->server_image) && class_exists($ServicesUpload)) {
                                    $ServicesUpload = new $ServicesUpload;
                                    $promises = [];
                                    $promises2 = [];
                                    foreach ($chapContent['content'] as $url) {
                                        $promises[] = $this->client->getAsync($url, $this->crawler->options)->then(
                                            function (ResponseInterface $response) use ($url, $output) {
                                                if ($response->getStatusCode() !== 200) {
                                                    $msg = "Empty data in $url";

                                                    $output->writeln($msg);
                                                    Model::getDB()->rollback();
                                                    exit();
                                                }

                                                return ($response->getBody()->getContents());
                                            },
                                            function (RequestException $e) use ($url, $output) {
                                                $msg = "Can't Get Data From $url";

                                                $output->writeln($msg);
                                                Model::getDB()->rollback();

                                                echo $e->getMessage();
                                                exit();
                                            }
                                        );
                                    }


                                    if (isset($this->config->using_multi_upload) && $this->config->using_multi_upload) {
                                        foreach ($promises as $promise) {
                                            $image_data = $promise->wait();
                                            $image_path = "manga/$MID/$CID/" . uniqid() . ".jpg";

                                            $promises2[] = $this->client->postAsync($this->site_url . "/services-upload/" . $this->config->server_image, ['form_params' => [
                                                'app_key' => Config::APP_KEY,
                                                'image_data' => $image_data,
                                                'image_path' => $image_path
                                            ]])->then(
                                                function (ResponseInterface $response) use ($progressBar) {
                                                    $res = json_decode($response->getBody()->getContents());
                                                    if (!isset($res->status)) {
                                                        return false;
                                                    }

                                                    $progressBar->advance();
                                                    return $res->url;
                                                }
                                            );

                                        }

                                        $promise = new EachPromise($promises2, [
                                            'concurrency' => 10,
                                            'fulfilled' => function ($image_url) use (&$CTimages, $output) {
                                                if (!$image_url) {
                                                    $msg = "Can't Save Image To " . $this->config->server_image;
                                                    $output->writeln($msg);

                                                    Model::getDB()->rollback();
                                                    exit();
                                                }
                                                if (!is_array($image_url)) {
                                                    $CTimages[] = $image_url;
                                                } else {
                                                    foreach ($image_url as $value) {
                                                        $CTimages[] = $value;
                                                    }
                                                }
                                            },
                                        ]);

                                        $promise->promise()->wait();

                                    } else {
                                        foreach ($promises as $promise) {
                                            $image_data = $promise->wait();
                                            $image_path = "manga/$MID/$CID/" . uniqid() . ".jpg";

                                            try {
                                                $image_url = $ServicesUpload->upload($image_data, $image_path);
                                                if (!is_array($image_url)) {
                                                    $CTimages[] = $image_url;
                                                } else {
                                                    foreach ($image_url as $value) {
                                                        $CTimages[] = $value;
                                                    }
                                                }

                                            } catch (\Exception $e) {
                                                $msg = "Can't Save Image To " . $this->config->server_image;
                                                $output->writeln($e);

                                                Model::getDB()->rollback();
                                                exit();
                                            }

                                            $i++;
                                            $progressBar->advance();
                                        }
                                    }

                                } else {
                                    $CTimages = $chapContent['content'];
                                }

                                $progressBar->finish();

                                $ChapCT = json_encode($CTimages);
                            }

                            Model::getDB()->where('id', $CTID)->update('chapter_data', [
                                'type' => $chapContent['type'],
                                'content' => $ChapCT,
                                'used' => 0,
                                'storage_name' => $this->config->server_image
                            ]);

                            Model::getDB()->where('id', $CID)->update('chapters', [
                                'hidden' => 0
                            ]);

                            Manga::UpdateLastChapter($MID);
                            Manga::getDB()->where('id', $MID)
                                ->update('mangas', [
                                    'last_update' => Manga::getDB()->now()]);

                        } catch (\Exception $e) {
                            Model::getDB()->rollback();
                        } finally {

                            Model::getDB()->commit();
                        }
                    }

                    // Update Manga Data
                    Manga::getDB()->where('id', $MID)
                        ->update('mangas', [
                            'cover' => $this->INFO['cover']
                        ]);

                    // perform a job during less than 30 seconds
                } catch (\Exception $e) {
                    Model::getDB()->insert('auto_logs', [
                        'title' => 'Unable to find data',
                        'url' => $url,
                        'description' => $e->getMessage(),
                    ]);

                } finally {
                    $lock->release();
                }
                // END single manga
            }

            $page++;
        }

        $this->release();
        return Command::SUCCESS;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        set_time_limit(0);

        $host = Config::DB_HOST;
        $dbName = Config::DB_NAME;

        $databaseConnectionOrDSN = "mysql:host=$host;dbname=$dbName";
        $this->store = new PdoStore($databaseConnectionOrDSN, ['db_username' => Config::DB_USER, 'db_password' => Config::DB_PASSWORD]);
        $this->factory = new LockFactory($this->store);

        $helper = $this->getHelper('question');

        $this->source = $input->getArgument('source');

        if (!$this->source) {
            $output->writeln('<comment>Bỏ trống mặc định = 0</comment>');

            $this->source = $helper->ask($input, $output,
                new ChoiceQuestion("Chọn nguồn: ", $this->scraplist())
            );

        }

        $this->config = json_decode(file_get_contents(ROOT_PATH . '/config/auto-manga.json'));

        // initialize
        $this->crawler = new ('\\Crawler\\' . $this->source);

        $this->client = new Client([
            "request.options" => array(
                $this->crawler->options
            )
        ]);

        $this->site_url = getConf('site')['site_url'];

        parent::initialize($input, $output); // TODO: Change the autogenerated stub
    }


}