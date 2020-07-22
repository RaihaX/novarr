<?php

namespace App\Http\Controllers;

use App\Novel;
use App\NovelChapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewChapters;

use Goutte;
use DataTables;
use DOMDocument;
use SimpleXMLElement;
use Storage;

use Carbon\Carbon;

class NovelChapterController extends Controller
{
    /**
     * The user repository instance.
     */
    protected $novelchapters;

    /**
     * Create a new controller instance.
     *
     * @param  UserRepository  $users
     * @return void
     */
    public function __construct(NovelChapter $novelchapters)
    {
        $this->novelchapters = $novelchapters;
    }

    public function datatables($id) {
        return DataTables::of($this->novelchapters->where('novel_id', $id)->where('blacklist', 0)->orderBy('status')->orderBy('book')->orderBy('chapter')->select('id', 'novel_id', 'status', 'book', 'chapter', 'label', 'created_at')->get())->toJson();
    }

    public function table_of_content_generator($data) {
        $result = array();
        $crawler = Goutte::request('GET', $data->translator_url);

        switch ($data->group_id) {
            case 1: // WuxiaWorld
                $crawler->filter('.chapter-item > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = trim($node->attr('href'));

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;

            case 2: // Gravity Tales
                $crawler = Goutte::request('GET', $data->translator_url . "/chapters");

                $crawler->filter('.table > tbody > tr > td > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = trim($node->attr('href'));

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 3: // Volare
                $crawler->filter('.chapter-item > a')->each(function ($node) use (&$result) {
//                    $check_for_chapters = explode(" ", $node->text());
//
//                    if ( strtolower($check_for_chapters[0]) == "chapter" || strtolower($check_for_chapters[0]) == "book" ) {
                        $label = trim($node->text());
                        $url = trim($node->attr('href'));

                        array_push($result, __tocChapterLabelGenerator($label, $url));
//                    }
                });
                break;
            case 4: // KobatoChan
                $crawler->filter('.entry-content > p > a')->each(function ($node, $key) use (&$result) {
                    if ( stripos(strtolower($node->text()), "chapter") === false ) {
                        $label = trim("Chapter " . $key . " - " . $node->text());
                    } else {
                        $label = $node->text();
                    }
                    $url = trim($node->attr('href'));

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 5: // LiberSpark
                $crawler->filter('.chapter-link-container > td > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 6: // Qidian
                $csrf = "1DAntuKsCJgB6YoT8mC9nBy0S6CA6SmVtBwVDZNG";
                $qidian_weird_token = "1555242888527";

                $chapter_list_array = file_get_contents("https://www.webnovel.com/apiajax/chapter/GetChapterList?_csrfToken=" . $csrf . "&bookId=" . $data->unique_id . "&_=" . $qidian_weird_token);

                if ( is_array(json_decode($chapter_list_array)) || is_object(json_decode($chapter_list_array)) ) {
                    foreach (json_decode($chapter_list_array) as $key => $item) {
                        if ($key == "data") {
                            foreach ($item as $k => $i) {
                                if ($k == "bookInfo") {
                                    $book_id = $i->bookId;
                                    $book_name = str_replace(" ", "-", $i->bookName);
                                    $book_name = str_replace(":", "%3A", $book_name);
                                    $book_name = str_replace(",", "%2C", $book_name);
                                }

                                if ($k == "volumeItems") {
                                    foreach ($i as $k_v => $v) {
                                        foreach ($v->chapterItems as $c) {
                                            $chapter_name = str_replace(" ", "-", $c->name);

                                            $label = "Book " . $v->index . " Chapter " . $c->index . " - " . $c->name;
                                            $url = "https://www.webnovel.com/book/" . $book_id . "/" . $c->id . "/" . $book_name . "/" . $chapter_name;

                                            array_push($result, __tocChapterLabelGenerator($label, $url));
                                        }
                                    }
                                } else {
                                    if ($k == "chapterItems") {
                                        foreach ($i as $c) {
                                            $chapter_name = str_replace(" ", "-", $c->chapterName);

                                            $label = "Chapter " . $c->chapterIndex . " - " . $c->chapterName;
                                            $url = "https://www.webnovel.com/book/" . $book_id . "/" . $c->chapterId . "/" . $book_name . "/" . $chapter_name;

//                                            echo var_dump($label);

                                            array_push($result, __tocChapterLabelGenerator($label, $url));
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                break;
            case 7: // Creative Novels
//                $data = json_decode($data->json);
//
//                foreach ( $data->data as $item ) {
//                    $anchor = new SimpleXMLElement($item[2]);
//                    $label = $anchor[0];
//                    $url = $anchor["href"];
//
//                    array_push($result, __tocChapterLabelGenerator($label, $url));
//                }

                $crawler->filter('.post_box > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 8: // Dreams of Jianghu
                $crawler->filter('.entry-content > p > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    $labelArr = explode('“', $label);

                    if ( count($labelArr) > 1 ) {
                        $chapterNumber = __convertWordToNumber(strtolower(trim(str_replace("Chapter ", "", $labelArr[0]))));

                        $label = "Chapter " . $chapterNumber . " - " . str_replace("”", "", $labelArr[1]);

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    }
                });
                break;
            case 9: // Fiction Press
                $dom = new DOMDocument();
                @$dom->loadHTML(file_get_contents($data->translator_url));

                foreach ( $dom->getElementById('chap_select')->childNodes as $key => $item ) {
                    $label = "Chapter " . str_replace(".", " - ", $item->textContent);
                    $url = $data->chapter_url . ($key + 1) . "/" . str_replace(" ", "-", $data->name);

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                }
                break;
            case 10:
                $crawler->filter('table > tbody > tr > td > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                if ( count($result) == 0 ) {
                    $crawler->filter('.entry-content > p > a')->each(function ($node, $key) use (&$result) {
                        $label = trim($node->text());
                        $url = $node->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    });
                }

                if ( count($result) == 0 ) {
                    $crawler->filter('.entry-content > div > p > a')->each(function ($node, $key) use (&$result) {
                        $label = trim($node->text());
                        $url = $node->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    });
                }
                break;
            case 11: // Royal Road
                $crawler->filter('table > tbody > tr > td > a')->each(function ($node, $key) use (&$result) {
                    $url = $node->attr('href');

                    if ( $url !== NULL ) {
//                        if ( stripos(strtolower($node->text()), "chapter") === false ) {
//                            if ( $key == 0 ) {
//                                $counter = $key;
//                            } else {
//                                $counter = $key - 1;
//                            }
//
//                            $label = "Chapter " . $counter . " - " . trim($node->text());
//                        } else {
                            $label = trim($node->text());
//                        }

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    }
                });
                break;
            case 12: // Halosty's Tales
                $crawler->filter('.entry-content > p > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 13: // Jiggly Puff's Diary
                $crawler->filter('.w4pl-inner > ul > li > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 14: // Blue Silver Translations
                $crawler->filter('.entry-content > ul > li > a')->each(function ($node, $key) use (&$result) {
                    $label = "Chapter " . trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 15: // Translation Nations
                $crawler->filter('.entry-content > table > tbody > tr > td > a')->each(function ($node, $key) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                if ( count($result) == 0 ) {
                    $crawler->filter('.panel-body > table > tbody > tr > td > a')->each(function ($node, $key) use (&$result) {
                        $label = trim($node->text());
                        $url = $node->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    });
                }
                break;
            case 16: // DM Translations
                $crawler->filter('.entry-content > p > a')->each(function ($node) use (&$result) {
                    if ( stripos(strtolower($node->text()), "chapter") === false ) {
                        $label = "Chapter " . trim($node->text());
                    } else {
                        $label = trim($node->text());
                    }
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 17: // Wolfie Honyaku
                $crawler->filter('.text-6404-0-0-4 > div > p > a')->each(function ($node) use (&$result) {
                    if ( stripos(strtolower($node->text()), "chapter") === false ) {
                        $label = "Chapter " . trim($node->text());
                    } else {
                        $label = trim($node->text());
                    }
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 18: // Divine Dao Library
                $crawler->filter('.post-content > div > ul > li > span > a')->each(function ($node) use (&$result) {
                    if ( stripos(strtolower($node->text()), "chapter") === false ) {
                        $label = "Chapter " . trim($node->text());
                    } else {
                        $label = trim($node->text());
                    }
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                $crawler->filter('.post-content > div > ul > li > a')->each(function ($node) use (&$result) {
                    if ( stripos(strtolower($node->text()), "chapter") === false ) {
                        $label = "Chapter " . trim($node->text());
                    } else {
                        $label = trim($node->text());
                    }
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 19: // WuxiaNation

                break;
            case 20: // Myoniyoni Translations
                $crawler->filter('.x-accordion > .x-accordion-group > .x-accordion-body > .x-accordion-inner > a')->each(function ($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                if ( count($result) == 0 ) {
                    $crawler->filter('.x-accordion > .x-accordion-group > .x-accordion-body > .x-accordion-inner > p > a')->each(function ($node) use (&$result) {
                        $label = trim($node->text());
                        $url = $node->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    });
                }

                $crawler->filter('.x-accordion > .x-accordion-group > .x-accordion-body > .x-accordion-inner > p')->each(function ($node) use (&$result) {
                    $node->filter('a')->each(function($item) use (&$result) {
                        $label = trim($item->text());
                        $url = $item->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    });
                });
                break;
//            case 21: // Lesyt
//                $crawler->filter('.container-fluid > ul > li > a')->each(function ($node) use (&$result) {
//                    $label = trim($node->text());
//                    $url = $node->attr('href');
//
//                    array_push($result, __tocChapterLabelGenerator($label, $url));
//                });
//                break;
            case 22: // Humanity, Fuck Yeah
                $crawler->filter('.menu > li > a')->each(function ($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 23: // A Practical Guide to Evil
                for ( $i = 0; $i < count($crawler->filter('.entry-content > ul')); $i++ ) {
                    $book = $crawler->filter('.entry-content > ul')->eq($i);

                    $book->filter('li > a')->each(function($node) use ($i, &$result) {
                        $label = "Book " . ($i + 1) . " - " . trim($node->text());
                        $url = $node->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, $url));
                    });
                }
                break;
            case 24: // Snowy Publications
                $crawler->filter('.page-item-6 > ul > .page_item_has_children > ul > li > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 25: // The Iron Teeth Serial
                for ( $i = 0; $i < count($crawler->filter('.page > .su-accordion')); $i++ ) {
                    $book = $crawler->filter('.page > .su-accordion')->eq($i);

                    for ( $j = 0; $j < count($book->filter('.su-spoiler-content')); $j++ ) {
                        $arc = $book->filter('.su-spoiler-content')->eq($j);

                        $arc->filter('ul > li > a')->each(function($node, $a) use ($i, $j, &$result) {
                            $label = "Book " . ($i + 1) . " - Chapter " . ($j + 1) . "." . $a . " - " . trim($node->text());
                            $url = $node->attr('href');

                            if ( stripos(strtolower($url), "patreon") === false ) {
                                array_push($result, __tocChapterLabelGenerator($label, $url));
                            }
                        });
                    }
                }
                break;
            case 26: // Parahumans
                $crawler->filter('.entry-content > p > strong > a')->each(function($node) use (&$result) {
                    $label = "Chapter " . trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                $crawler->filter('.entry-content > div > strong > a')->each(function($node) use (&$result) {
                    $label = "Chapter " . trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 27: // Oppa Translations
                $crawler->filter('.entry-content > p > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 28: // Dark Blood Age
                $crawler->filter('.entry-content > div > table > tbody > tr > td > a')->each(function($node) use (&$result) {
                    $label = filter_var($node->text(), FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH);
                    $label = str_replace("Chapter", "Chapter ", $label);
                    $label = str_replace("  ", " ", $label);
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 29: // Elysiel
                $crawler->filter('.entry-content > p > a')->each(function ($node, $key) use (&$result) {
                    if ( stripos(strtolower($node->text()), "chapter") === false ) {
                        $label = trim("Chapter " . $key . " - " . $node->text());
                    } else {
                        $label = $node->text();
                    }
                    $url = trim($node->attr('href'));

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 30: // Kokuma Translations
                $crawler->filter('.entry-content > p > strong > span > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                $crawler->filter('.entry-content > p > span > strong > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 31: // The Wandering Inn
                $crawler->filter('.entry-content > p > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 32: // Light Novels Translations
                $crawler->filter('.su-spoiler-content > p > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));

//                    echo var_dump(__tocChapterLabelGenerator($label, $url));
                });
                break;
            case 33: // Totally Insane Translation
                $crawler->filter('.tab-pane > p > em > u > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                $crawler->filter('.tab-pane > p > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                $crawler->filter('.tab-pane > p > em > span > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                $crawler->filter('.tab-pane > p > span > em > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });

                $crawler->filter('.chapters-acc > li > a')->each(function($node) use (&$result) {
                    $label = trim($node->text());
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 34: // Light Novels Bastion
                $crawler->filter('.container > .row > div > section > ul')->each(function($node) use (&$result) {
                    foreach ( $node->filter("li > a > span")  as $item ) {
                        $item->parentNode->removeChild($item);
                    }
                });

                $crawler->filter('.container > .row > div > section > ul')->each(function($node) use (&$result) {
                    $label = str_replace("The Tutorial Is Too Hard ", "Chapter ", trim($node->filter('li > a')->text()));
                    $label = str_replace("The Tutorial is too Hard ", "Chapter ", $label);
                    $label = str_replace("Max Level Newbie ", "Chapter ", $label);
                    $url = $node->filter('li > a')->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
            case 35: // Novel Full
                $total = 0;

                $crawler->filter('.last > a')->each(function($node) use (&$total) {
                    $a = explode("&", $node->attr('href'));
                    $b = explode("=", $a[0]);

                    $total = $b[1];
                });

                for ( $i = 24; $i <= 50; $i++ ) {
                    $crawler = Goutte::request('GET', $data->translator_url . "?page=" . $i . "&per_page=50");
                    $crawler->filter('.list-chapter > li > a')->each(function($node) use (&$result, &$total) {
                        $label = trim($node->attr('title'));
                        $label = str_replace("Dragoon ", "Chapter ", $label);
                        $url = $node->attr('href');

                        array_push($result, __tocChapterLabelGenerator($label, "http://novelfull.com" . $url));
                    });
                }
                break;
            case 36: // Korea Novels
                $crawler->filter('ul > li > a')->each(function($node) use (&$result) {
                    $label = $node->attr('title');
                    $url = $node->attr('href');

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
//            case 37: // Novel Universe
//                $total = 0;
//
//                $crawler->filter('.info_se1 > a')->each(function($node) use (&$total) {
//                    $a = trim($node->text());
//
//                    if ( strpos($a, 'Chapter') == true ) {
//                        $total = intval(str_replace("Chapter ", "", $a)) / 20;
//                    }
//
//                    $total += 1;
//                });
//
//                for ( $i = 1; $i <= $total; $i++ ) {
//                    $crawler = Goutte::request('GET', $data->translator_url . "?page_c=" . $i);
//                    $crawler->filter('#chapters > li > a')->each(function($node) use (&$result, &$total) {
//                        $label = trim(preg_replace("/(\\d)([a-z])/i", "$1 $2", $node->text()));
//                        $url = $node->attr('href');
//
//                        array_push($result, __tocChapterLabelGenerator($label, "https://www.noveluniverse.com" . $url));
//                    });
//                }
//                break;
            case 38: // Box Novel
                $crawler->filter('.wp-manga-chapter > a')->each(function ($node, $key) use (&$result) {
                    $label = $node->text();
                    $url = trim($node->attr('href'));

                    array_push($result, __tocChapterLabelGenerator($label, $url));
                });
                break;
        }

        // remove nulls
        $result_data = array();
        $generateChapter = true;
        foreach ( $result as $item ) {
            if ( $item !== NULL ) {
                array_push($result_data, $item);

                if ( $item["chapter"] > 0 ) {
                    $generateChapter = false;
                }
            }
        }

        if ( $generateChapter == true ) {
            $result_chapters_data = $result_data;
            $result_data = array();
            foreach ( $result_chapters_data as $key => $item ) {
                array_push($result_data, array(
                    "label" => $item["label"],
                    "book" => $item["book"],
                    "url" => $item["url"],
                    "chapter" => ($key + 1)
                ));
            }
        }

        return $result_data;
    }

    public function all_novels_scraper() {
        foreach ( Novel::where('status', 0)->where('group_id', '!=', 37)->orderBy('name', 'asc')->get() as $n ) {
            echo $n->name . "\r\n";
            $toc = $this->table_of_content_generator($n);

            if ( $n->group_id != 6 ) {
                foreach ($toc as $item) {
                    $check_duplicate = NovelChapter::where('novel_id', $n->id)->where('chapter', round($item["chapter"], 2))->where('book', intval($item["book"]))->select('id')->first();

                    if (empty($check_duplicate)) {
                        if (!empty($item["label"]) && !empty($item["url"]) && !empty($item["chapter"])) {
                            $object = new NovelChapter();
                            $object->novel_id = $n->id;
                            $object->label = $item["label"];
                            $object->url = $item["url"];
                            $object->chapter = $item["chapter"];
                            $object->book = intval($item["book"]);
                            $object->save();
                        }
                    } else {
                        $check_duplicate->label = $item["label"];
                        $check_duplicate->chapter = $item["chapter"];
                        $check_duplicate->book = intval($item["book"]);
                        $check_duplicate->url = $item["url"];
                        $check_duplicate->save();
                    }
                }
            } else {
                foreach ($toc as $item) {
                    $urlArr = explode("/", str_replace("https://www.webnovel.com/book/", "", $item["url"]));

                    $check_duplicate = NovelChapter::where('novel_id', $n->id)->where('chapter', round($item["chapter"], 2))->select('id')->first();

                    if (empty($check_duplicate)) {
                        if (!empty($item["label"]) && !empty($item["url"]) && !empty($item["chapter"])) {
                            $object = new NovelChapter();
                            $object->novel_id = $n->id;
                            $object->label = $item["label"];
                            $object->url = $item["url"];
                            $object->chapter = $item["chapter"];
                            $object->book = intval($item["book"]);
                            $object->unique_id = $urlArr[1];
                            $object->save();
                        }
                    } else {
                        $check_duplicate->label = $item["label"];
                        $check_duplicate->chapter = $item["chapter"];
                        $check_duplicate->book = intval($item["book"]);
                        $check_duplicate->url = $item["url"];
                        $check_duplicate->unique_id = $urlArr[1];
                        $check_duplicate->save();
                    }
                }
            }
        }
    }

    public function all_novels_scraper_reverse() {
        foreach ( Novel::where('status', 0)->where('group_id', '!=', 37)->orderBy('name', 'desc')->get() as $n ) {
            echo $n->name . "\r\n";
            $toc = $this->table_of_content_generator($n);

            if ( $n->group_id != 6 ) {
                foreach ($toc as $item) {
                    $check_duplicate = NovelChapter::where('novel_id', $n->id)->where('chapter', round($item["chapter"], 2))->where('book', intval($item["book"]))->select('id')->first();

                    if (empty($check_duplicate)) {
                        if (!empty($item["label"]) && !empty($item["url"]) && !empty($item["chapter"])) {
                            $object = new NovelChapter();
                            $object->novel_id = $n->id;
                            $object->label = $item["label"];
                            $object->url = $item["url"];
                            $object->chapter = $item["chapter"];
                            $object->book = intval($item["book"]);
                            $object->save();
                        }
                    } else {
                        $check_duplicate->label = $item["label"];
                        $check_duplicate->chapter = $item["chapter"];
                        $check_duplicate->book = intval($item["book"]);
                        $check_duplicate->url = $item["url"];
                        $check_duplicate->save();
                    }
                }
            } else {
                foreach ($toc as $item) {
                    $urlArr = explode("/", str_replace("https://www.webnovel.com/book/", "", $item["url"]));

                    $check_duplicate = NovelChapter::where('novel_id', $n->id)->where('chapter', round($item["chapter"], 2))->select('id')->first();

                    if (empty($check_duplicate)) {
                        if (!empty($item["label"]) && !empty($item["url"]) && !empty($item["chapter"])) {
                            $object = new NovelChapter();
                            $object->novel_id = $n->id;
                            $object->label = $item["label"];
                            $object->url = $item["url"];
                            $object->chapter = $item["chapter"];
                            $object->book = intval($item["book"]);
                            $object->unique_id = $urlArr[1];
                            $object->save();
                        }
                    } else {
                        $check_duplicate->label = $item["label"];
                        $check_duplicate->chapter = $item["chapter"];
                        $check_duplicate->book = intval($item["book"]);
                        $check_duplicate->url = $item["url"];
                        $check_duplicate->unique_id = $urlArr[1];
                        $check_duplicate->save();
                    }
                }
            }
        }
    }

    public function novel_scraper($id)
    {
        $object = Novel::find($id);
        $novel_id = $object->id;

        $toc = $this->table_of_content_generator($object);

//        echo var_dump($toc);

        if ( $object->group_id != 6 ) {
            foreach ( $toc as $item ) {
                $check_duplicate = NovelChapter::where('novel_id', $novel_id)->where('chapter', round($item["chapter"], 2))->where('book', intval($item["book"]))->select('id')->first();
                if ( empty($check_duplicate) ) {
                    if ( !empty($item["label"]) && !empty($item["url"]) && !empty($item["chapter"]) ) {
                        $object = new NovelChapter();
                        $object->novel_id = $novel_id;
                        $object->label = $item["label"];
                        $object->url = $item["url"];
                        $object->chapter = $item["chapter"];
                        $object->book = intval($item["book"]);
                        $object->save();
                    }
                } else {
                    $check_duplicate->label = $item["label"];
                    $check_duplicate->chapter = $item["chapter"];
                    $check_duplicate->book = intval($item["book"]);
                    $check_duplicate->url = $item["url"];
                    $check_duplicate->save();
                }
            }
        } else {
            foreach ( $toc as $item ) {
                $check_duplicate = NovelChapter::where('novel_id', $novel_id)->where('chapter', round($item["chapter"], 2))->select('id')->first();
                if ( empty($check_duplicate) ) {
                    if ( !empty($item["label"]) && !empty($item["url"]) && !empty($item["chapter"]) ) {
                        $object = new NovelChapter();
                        $object->novel_id = $novel_id;
                        $object->label = $item["label"];
                        $object->url = $item["url"];
                        $object->chapter = $item["chapter"];
                        $object->book = intval($item["book"]);
                        $object->save();
                    }
                } else {
                    $check_duplicate->label = $item["label"];
                    $check_duplicate->chapter = $item["chapter"];
                    $check_duplicate->book = intval($item["book"]);
                    $check_duplicate->url = $item["url"];
                    $check_duplicate->save();
                }
            }
        }
    }

    public function chapter_generator($data) {
        $result = array();
        $novel_title = $data->novel->name;

        if ( substr($data->url, 0, 4) == "http" ) {
            $novel_url = $data->url;
        } else {
            $novel_url = $data->novel->group->url . $data->url;
        }

        if ( $data->novel->group->id == 6 ) {
            if ( $data->novel->alternative_url != "" ) {
                $c = floor($data->chapter);

                $novel_url = $data->novel->alternative_url . $c;
            }
        } else if ( $data->novel->group->id == 1 ) {
            if ( $data->novel->alternative_url != "" ) {
                $c = floor($data->chapter);

                $novel_url = $data->novel->alternative_url . $c;
            }
        } else if ( $data->novel->group->id == 3 ) {
            if ( $data->novel->alternative_url != "" ) {
                if ( $data->novel->id == 72 ) {
                    $c = str_replace(".", "-", $data->chapter);
                } else {
                    $c = floor($data->chapter);
                }

                $novel_url = $data->novel->alternative_url . $c;
            }
        }

        $crawler = Goutte::request('GET', $novel_url);

        switch ($data->novel->group->id) {
            case 1: // WuxiaWorld
                $crawler->filter('.fr-view > p')->each(function ($node) use (&$result, $novel_title) {
                    if ( $node->text() != $novel_title ) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    }
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 9 ) {
                    $crawler->filter('.fr-view > div > p')->each(function ($node) use (&$result, $novel_title) {
                        if ( $node->text() != $novel_title ) {
                            array_push($result, '<p>' . $node->text() . '</p>');
                        }
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.fr-view > div')->each(function ($node) use (&$result, $novel_title) {
                        if ( $node->text() != $novel_title ) {
                            array_push($result, '<p>' . $node->text() . '</p>');
                        }
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.fr-view > div > div > p')->each(function ($node) use (&$result, $novel_title) {
                        if ( $node->text() != $novel_title ) {
                            array_push($result, '<p>' . $node->text() . '</p>');
                        }
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.fr-view > div > div > div > p')->each(function ($node) use (&$result, $novel_title) {
                        if ( $node->text() != $novel_title ) {
                            array_push($result, '<p>' . $node->text() . '</p>');
                        }
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.fr-view > div > div > div > div > p')->each(function ($node) use (&$result, $novel_title) {
                        if ( $node->text() != $novel_title ) {
                            array_push($result, '<p>' . $node->text() . '</p>');
                        }
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.fr-view')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.text-left > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->html() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.text-left > div > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->html() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.text-left > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->html() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $result = __splitLargeString($result);
                }
                break;
            case 2: // Gravity Tales
                $crawler->filter('.fr-view > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('.Paragraph')->each(function ($node) use (&$result) {
//                        if ( $node->parents()->filter('.fr-view')->attr('id') != "chapterNotes" ) {
                            array_push($result, '<p>' . $node->text() . '</p>');
//                        }
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.fr-view > div > div > p')->each(function ($node) use (&$result) {
                        if ( $node->parents()->filter('.fr-view')->attr('id') != "chapterNotes" ) {
                            array_push($result, '<p>' . $node->text() . '</p>');
                        }
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.fr-view > header > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.fr-view')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);

                    $result = __splitLargeString($result);
                }
                break;
            case 3: // Volare
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('.entry-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.entry-content > p > span')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.cha-words > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.text-left > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->html() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.chapter-content3 > div > p > strong')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->html() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 4: // KobatoChan
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                if ( count($result) < 9 ) {
                    $crawler->filter('.entry-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });
                }
                break;
            case 5: // LiberSpark
                $crawler->filter('.reader-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });
                break;
            case 6: // Qidian
//                $chapter_data = json_decode(file_get_contents("https://www.webnovel.com/apiajax/chapter/GetContent?_csrfToken=" . $this->csrf . "&bookId=" . $data->novel->unique_id . "&chapterId=" . $data->unique_id . "&_=" . $this->qidian_token));
//
//                foreach ( preg_split( '/\r\n|\r|\n/', $chapter_data->data->chapterInfo->content) as $item ) {
//                    array_push($result, "<p>" . __cleanseChapterContent($item) . "<p>");
//                }

                $crawler->filter('.cha-words > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->html() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }

                $result = __cleanseChapterArray($result);

                if ( count($result) < 3 ) {
                    $crawler->filter('.fr-view > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->html() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);

                    if ( count($result) < 9 ) {
                        $crawler->filter('.fr-view > div > div > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.fr-view')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('#chapter-content > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->text() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('#vung_doc > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.chapter-content3 > div > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.chapter-content3 > div')->each(function ($node) use (&$result) {
                            if ( strlen($node->html()) > 1000 ) {
                                $paragraphs = preg_split('/<br><br>+/', $node->html());

                                foreach ( $paragraphs as $p ) {
                                    if ( strlen($p) > 0 ) {
                                        array_push($result, "<p>" . $p. "<p>");
                                    }
                                }
                            }
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('#list_chapter > div')->each(function ($node) use (&$result) {
                            if ( strlen($node->html()) > 1000 ) {
                                $paragraphs = preg_split('/<br><br>+/', $node->html());

                                foreach ( $paragraphs as $p ) {
                                    if ( strlen($p) > 0 ) {
                                        array_push($result, "<p>" . $p. "<p>");
                                    }
                                }
                            }
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.text-left > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.text-left > div > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $crawler->filter('.text-left > div > div > p')->each(function ($node) use (&$result) {
                            array_push($result, "<p>" . $node->html() . "<p>");
                        });

                        $result = __cleanseChapterArray($result);
                    }

                    if ( count($result) < 9 ) {
                        $result = __splitLargeString($result);
                    }
                }
                break;
            case 7: // Creative Novels
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }
                break;
            case 8: // Dreams of JiangHu
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });
                break;
            case 9: // FictionPress
                $result = array();
                $dom = new DOMDocument();
                @$dom->loadHTML(file_get_contents($novel_url));

                foreach ( $dom->getElementById('storytext')->childNodes as $item ) {
                    array_push($result, "<p>" . $item->textContent . "</p>");
                }

                $result = __cleanseChapterArray($result);
                break;
            case 10: // Radiant Translation
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) == 0 ) {
                    $crawler->filter('article > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) == 0 ) {
                    $crawler->filter('.fr-view')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) == 0 ) {
                    $crawler->filter('.chapter-body > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 11: // Royal Road
                $crawler->filter('.chapter-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) == 0 ) {
                    $crawler->filter('.chapter-content > div > div')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) == 0 ) {
                    $result = $crawler->filter('.chapter-content')->text();

                    $result = __cleanseChapterArray($result);
                }

                if ( is_array($result) ) {
                    if ( count($result) < 6 ) {
                        $result = __splitLargeString($result);
                    }
                } else {
                    $result = __splitLargeString($result);
                }
                break;
            case 12: // Halosty's Tales
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);
                break;
            case 13: // Jiggly Puff's Diary
                $crawler->filter('.entry-content > div > span > span > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) == 0 ) {
                    $crawler->filter('.entry-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) == 0 ) {
                    $crawler->filter('.entry-content > div > span')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) == 0 ) {
                    $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.entry-content > div > span > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.entry-content > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 14: // Blue Silver Translations
                $iframe = $crawler->filter('.entry-content > iframe')->each(function ($node) {
                    return $node->attr('src');
                });

                if ( !isset($iframe[0]) ) {
                    $iframe = $crawler->filter('.entry-content > p > iframe')->each(function ($node) {
                        return $node->attr('src');
                    });
                }

                $crawler = Goutte::request('GET', $iframe[0]);
                $crawler->filter('p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

//                $crawler->filter('.book-intro-para > p')->each(function ($node) use (&$result) {
//                    array_push($result, '<p>' . $node->text() . '</p>');
//                });
//
//                $result = __cleanseChapterArray($result);
                break;
            case 15: // Translation Nation
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) == 0 ) {
                    $crawler->filter('.entry-content > div > p > span')->each(function ($node) {
                        foreach ($node as $item) {
                            $item->parentNode->removeChild($item);
                        }
                    });

                    $crawler->filter('.entry-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __splitLargeString($result);
                }
                break;
            case 16: // DM Translations
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 9 ) {
                    $crawler->filter('.entry-content > div > div')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $crawler->filter('.entry-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }
                break;
            case 17: // Wolfie Honyaku
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);
                break;
            case 18: // Divine Dao Library
                $crawler->filter('.post-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) == 0 ) {
                    $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 19: // WuxiaNation
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);
                break;
            case 20: // Myoniyoni Translations
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);
                break;
//            case 21: // Lesyt
//                $crawler->filter('.container-fluid > div > p')->each(function ($node) use (&$result) {
//                    array_push($result, "<p>" . $node->text() . "<p>");
//                });
//
//                $result = __cleanseChapterArray($result);
//                break;
            case 22: // Humanity, Fuck Yeah
                $crawler->filter('.field-item > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);
                break;
            case 23: // A Practical Guide to Evil
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }
                break;
            case 24: // Snowy Publications
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }
                break;
            case 25: // The Iron Teeth Serial
                $crawler->filter('.post_content > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) == 0 ) {
                    $crawler->filter('.post_content > div > div > p')->each(function ($node) {
                        array_push($result, "<p>" . $node->text() . "<p>");
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }
                break;
            case 26: // Parahumans
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);
                break;
            case 27: // Oppa Translations
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);
                break;
            case 28: // Dark Blood Age
                $crawler->filter('.entry-content > div')->each(function ($node) use (&$result) {
                    array_push($result, $node->html());
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $result = array();
                    $crawler->filter('.entry-content')->each(function ($node) use (&$result) {
                        array_push($result, $node->html());
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }

                if ( count($result) < 10 ) {
                    $result = array();
                    $crawler->filter('.entry-content > span')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 29: // Elysiel
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 9 ) {
                    $crawler->filter('.entry-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });
                }

                $result = __cleanseChapterArray($result);
                break;
            case 30: // Kokuma Translations
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('.public-DraftStyleDefault-block > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 31: // The Wandering Inn
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);
                break;
            case 32: // Light Novels Translations
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);
                break;
            case 33: // Totally Insane Translations
                $crawler->filter('.entry-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('.entry-content > div > div > div > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.the-content > p > span')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.the-content > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.the-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.post-content > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.post-content > p > span')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 34: // Light Novels Bastion
                $crawler->filter('._1V5-components-Post--postContentWrapper > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                $crawler->filter('.container > .row > div > section > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                if ( count($result) < 9 ) {
                    $result = __splitLargeString($result);
                }

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('.container > .row > div > section > div')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 35: // Novel Full
                $crawler->filter('#chapter-content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > blockquote > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('#chapter-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.reading-content > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.reading-content > div > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
            case 36: // Korea Novels
                $crawler->filter('#content > p')->each(function ($node) use (&$result) {
                    array_push($result, '<p>' . $node->text() . '</p>');
                });

                $result = __cleanseChapterArray($result);
                break;
//            case 37: // Novel Universe
//                $crawler->filter('.page_conX > p')->each(function ($node) use (&$result) {
//                    array_push($result, '<p>' . $node->text() . '</p>');
//                });
//
//                $result = __cleanseChapterArray($result);
//
//                if ( count($result) < 5 ) {
//                    $crawler->filter('.Section1 > p')->each(function ($node) use (&$result) {
//                        array_push($result, '<p>' . $node->text() . '</p>');
//                    });
//
//                    $result = __cleanseChapterArray($result);
//                }
//                break;
            case 38: // Box Novel
                $crawler->filter('.reading-content > .text-left > p')->each(function ($node) use (&$result) {
                    array_push($result, "<p>" . $node->text() . "<p>");
                });

                $result = __cleanseChapterArray($result);

                if ( count($result) < 5 ) {
                    $crawler->filter('.reading-content > .text-left > .entry-header > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.reading-content > .text-left > .entry-content > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.reading-content > .text-left > div > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }

                if ( count($result) < 5 ) {
                    $crawler->filter('.cha-words > p')->each(function ($node) use (&$result) {
                        array_push($result, '<p>' . $node->text() . '</p>');
                    });

                    $result = __cleanseChapterArray($result);
                }
                break;
        }

        if ( is_array($result) ) {
            $result = __cleanseChapterArray($result);
        }

        return $result;
    }

    public function convertQidianToPirateSite($id) {
        foreach ( $this->novelchapters->with('novel.group')->where('novel_id', $id)->where('status', 0)->get() as $item ) {
            if ( $id == 3 ) {
                $url = "https://boxnovel.com/novel/carefree-path-of-dreams/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 113 ) {
                $url = "https://boxnovel.com/novel/true-martial-world/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 114 ) {
                $url = "https://boxnovel.com/novel/library-of-heavens-path/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 115 ) {
                $url = "https://boxnovel.com/novel/release-that-witch/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 116 ) {
                $url = "https://boxnovel.com/novel/shadow-hack/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 117 ) {
                $url = "https://boxnovel.com/novel/war-sovereign-soaring-the-heavens/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 119 ) {
                $url = "https://boxnovel.com/novel/the-kings-avatar/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 120 ) {
                $url = "https://boxnovel.com/novel/night-ranger/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 121 ) {
                $url = "https://boxnovel.com/novel/ancient-godly-monarch/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 122 ) {
                $url = "https://boxnovel.com/novel/the-strongest-system/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 123 ) {
                $url = "https://boxnovel.com/novel/mmorpg-martial-gamer/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 124 ) {
                $url = "https://boxnovel.com/novel/im-really-a-superstar/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 126 ) {
                $url = "https://boxnovel.com/novel/super-gene/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 127 ) {
                $url = "https://www.readlightnovel.org/mmorpg-rebirth-of-the-legendary-guardian/chapter-" . floor($item->chapter);

                $item->url = $url;
                $item->save();
            } else if ( $id == 128 ) {
                $url = "https://boxnovel.com/novel/lord-xue-ying/chapter-" . floor($item->chapter);

                $item->url = $url;
                $item->save();
            } else if ( $id == 129 ) {
                $url = "https://boxnovel.com/novel/castle-of-black-iron/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 130 ) {
                $url = "https://boxnovel.com/novel/god-of-slaughter/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 131 ) {
                $url = "https://boxnovel.com/novel/strongest-abandoned-son/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 133 ) {
                $url = "https://boxnovel.com/novel/swallowed-star/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 136 ) {
                $url = "https://boxnovel.com/novel/seeking-the-flying-sword-path/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 137 ) {
                $url = "https://boxnovel.com/novel/forty-millenniums-of-cultivation/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 138 ) {
                $url = "https://boxnovel.com/novel/the-strongest-gene/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 140 ) {
                $url = "https://boxnovel.com/novel/legend-of-ling-tian/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 142 ) {
                $url = "https://boxnovel.com/novel/i-have-a-mansion-in-the-post-apocalyptic-world/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 143 ) {
                $url = "https://boxnovel.com/novel/alchemy-emperor-of-the-divine-dao/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 144 ) {
                $url = "https://boxnovel.com/novel/realms-in-the-firmament/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 147 ) {
                $url = "https://boxnovel.com/novel/tales-of-herding-gods/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 149 ) {
                $url = "https://boxnovel.com/novel/neet-receives-a-dating-sim-game-leveling-system/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 150 ) {
                $url = "https://boxnovel.com/novel/the-devils-cage/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 155 ) {
                $url = "https://boxnovel.com/novel/the-wizard-world/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 162) {
                $url = "https://boxnovel.com/novel/pursuit-of-the-truth/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 164 ) {
                $url = "https://boxnovel.com/novel/gourmet-food-supplier/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 167 ) {
                $url = "https://boxnovel.com/novel/throne-of-magical-arcana/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 169 ) {
                $url = "https://boxnovel.com/novel/the-experimental-log-of-the-crazy-lich/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 184 ) {
                $url = "https://boxnovel.com/novel/reverend-insanity/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 186 ) {
                $url = "https://boxnovel.com/novel/joy-of-life/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 221 ) {
                $url = "https://boxnovel.com/novel/evil-emperors-wild-consort/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 226 ) {
                $url = "https://boxnovel.com/novel/crossing-to-the-future-its-not-easy-to-be-a-man/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 228 ) {
                $url = "https://boxnovel.com/novel/cultivation-chat-group/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 233 ) {
                $url = "https://boxnovel.com/novel/ghost-emperor-wild-wife-dandy-eldest-miss/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 267 ) {
                $url = "https://boxnovel.com/novel/gate-of-god/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 276 ) {
                $url = "https://boxnovel.com/novel/advent-of-the-archmage/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 277 ) {
                $url = "https://boxnovel.com/novel/the-nine-cauldrons/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 279 ) {
                $url = "https://boxnovel.com/novel/a-billion-stars-cant-amount-to-you/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 281 ) {
                $url = "https://boxnovel.com/novel/full-marks-hidden-marriage-pick-up-a-son-get-a-free-husband/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 283 ) {
                $url = "https://boxnovel.com/novel/trial-marriage-husband-need-to-work-hard/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 284 ) {
                $url = "https://boxnovel.com/novel/perfect-secret-love-the-bad-new-wife-is-a-little-sweet/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 288 ) {
                $url = "https://boxnovel.com/novel/venerated-venomous-consort/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 293 ) {
                $url = "https://boxnovel.com/novel/spare-me-great-lord/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 297 ) {
                $url = "https://boxnovel.com/novel/a-valiant-life/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 299 ) {
                $url = "https://boxnovel.com/novel/the-legend-of-futian/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 304 ) {
                $url = "https://boxnovel.com/novel/the-legend-of-chu-qiao-division-11s-princess-agent/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 311 ) {
                $url = "https://boxnovel.com/novel/godfather-of-champions/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 313 ) {
                $url = "https://boxnovel.com/novel/the-empresss-gigolo/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 317 ) {
                $url = "https://boxnovel.com/novel/god-emperor/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 321 ) {
                $url = "https://boxnovel.com/novel/unrivaled-medicine-god/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 325 ) {
                $url = "https://boxnovel.com/novel/kingdoms-bloodline/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 335 ) {
                $url = "https://boxnovel.com/novel/gourmet-of-another-world/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 337 ) {
                $url = "https://boxnovel.com/novel/the-world-turned-into-a-game-after-i-woke-up/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else if ( $id == 341 ) {
                $url = "https://boxnovel.com/novel/dragon-kings-son-in-law/chapter-" . floor($item->chapter) . "/";

                $item->url = $url;
                $item->save();
            } else {
                $novel = str_replace(" ", "-", strtolower($item->novel->name));
                $novel = str_replace("?", "", $novel);
                $novel = str_replace("!", "", $novel);
                $novel = str_replace("(", "", $novel);
                $novel = str_replace(")", "", $novel);
                $novel = str_replace("[", "", $novel);
                $novel = str_replace("]", "", $novel);
                $novel = str_replace("'", "", $novel);
                $novel = str_replace("’", "", $novel);
                $novel = str_replace(":", "", $novel);
                $novel = str_replace(",", "", $novel);

                $label = explode(" - ", $item->label);
                $chapter_label = str_replace(" ", "-", trim(strtolower(str_replace("  ", " ", $label[1]))));
                $chapter_label = str_replace("?", "", $chapter_label);
                $chapter_label = str_replace("!", "", $chapter_label);
                $chapter_label = str_replace("(", "", $chapter_label);
                $chapter_label = str_replace(")", "", $chapter_label);
                $chapter_label = str_replace("[", "", $chapter_label);
                $chapter_label = str_replace("]", "", $chapter_label);
                $chapter_label = str_replace("'", "", $chapter_label);
                $chapter_label = str_replace("’", "", $chapter_label);
                $chapter_label = str_replace(".", "", $chapter_label);
                $chapter_label = str_replace(",", "", $chapter_label);
                $chapter_label = str_replace(".", "", $chapter_label);
                $chapter_label = str_replace("*", "", $chapter_label);
                $chapter_label = str_replace("“", "", $chapter_label);
                $chapter_label = str_replace("”", "", $chapter_label);
                $chapter_label = str_replace("‘'", "", $chapter_label);
                $chapter_label = str_replace(":", "", $chapter_label);
                $chapter_label = str_replace("$", "", $chapter_label);
                $chapter_label = str_replace("？", "", $chapter_label);
                $chapter_label = str_replace("！", "", $chapter_label);
                $chapter_label = str_replace("（", "", $chapter_label);
                $chapter_label = str_replace("）", "", $chapter_label);
                $chapter_label = str_replace("--", "-", $chapter_label);

                $url = "http://novelfull.com/" . $novel . "/chapter-" . floor($item->chapter) . "-" . $chapter_label . ".html";

                $item->url = $url;
                $item->save();
            }
        }
    }

    public function chapter_scraper(Request $request, $id) {
        $object = $this->novelchapters->with('novel.group')->find($id);

        $chapter = $this->chapter_generator($object);

        if ( $request->preview == 1 ) {
            return response()->json($chapter);
        } else {
            if ( $object->novel->group->id == 6 ) {
                $description = "";
                foreach ( $chapter as $c ) {
                    $description .= $c;
                }

                if ( str_word_count($description) > 250 ) {
                    $object->description = $description;
                    if ( trim($description) != "" ) {
                        $object->status = 1;
                    }
                    $object->download_date = Carbon::now();
                    $object->save();
                } else {
                    $object->description = "";
                    $object->status = 0;
                    $object->save();
                }

                return response()->json($object);
            } else {
                $description = "";
                foreach ( $chapter as $c ) {
                    $description .= $c;
                }

                if ( str_word_count($description) > 250 ) {
                    $object->description = $description;
                    if ( trim($description) != "" ) {
                        $object->status = 1;
                    }
                    $object->download_date = Carbon::now();
                    $object->save();
                } else {
                    $object->description = "";
                    $object->status = 0;
                    $object->save();
                }

                return response()->json($object);
            }
        }
    }

    public function new_chapters_scraper($id) {
        foreach ($this->novelchapters->with('novel.group')->where('status', 0)->where('blacklist', 0)->where('novel_id', $id)->get() as $item ) {
            $chapter = $this->chapter_generator($item);

            if ( $item->novel->group->id == 6 ) {
                $description = "";
                foreach ( $chapter as $c ) {
                    $description .= $c;
                }

                if ( str_word_count($description) > 250 ) {
                    $item->description = $description;

                    if ( trim($description) != "" ) {
                        $item->status = 1;
                    }
                    $item->download_date = Carbon::now();
                    $item->save();
                }
            } else {
                $description = "";
                foreach ( $chapter as $c ) {
                    $description .= $c;
                }

                if ( str_word_count($description) > 275 ) {
                    $item->description = $description;

                    if ( trim($description) != "" ) {
                        $item->status = 1;
                    }
                    $item->download_date = Carbon::now();
                    $item->save();
                }
            }
        }
    }

    public function all_new_chapters_scraper() {
        $newChapters = array();

        Novel::where('status', 0)->where('group_id', '!=', 37)->whereHas('chapters', function($q) {
            $q->where('status', 0)->where('blacklist', 0);
        })->with(['chapters' => function($q) {
            $q->where('status', 0)->where('blacklist', 0)->orderBy('book')->orderBy('chapter');
        }])->orderBy('name', 'desc')->chunk(5, function ($novels) use (&$newChapters) {
            foreach ( $novels as $novel ) {
                if ( count($novel->chapters) > 0 ) {
                    foreach ( $novel->chapters as $item ) {
                        $chapter = $this->chapter_generator($item);

                        $description = "";
                        foreach ( $chapter as $c ) {
                            $description .= $c;
                        }

                        if ( str_word_count($description) > 250 ) {
                            $progress = $novel->no_of_chapters == 0 ? 0 : round(($item->chapter / $novel->no_of_chapters * 100), 2);

                            array_push($newChapters, array(
                                'novel' => $novel->name,
                                'label' => $item->label,
                                'chapter' => $item->chapter,
                                'book' => $item->book,
                                'progress' => number_format($progress, 2, ".", ",")
                            ));

//                            echo $novel->name . " - " . $item->chapter . "\r\n";

                            $item->description = $description;

                            if ( trim($description) != "" ) {
                                $item->status = 1;
                            }
                            $item->download_date = Carbon::now();
                            $item->save();
                        } else {
//                            echo $item->novel->name . " - " . $item->chapter . " (Incomplete)\r\n";
                        }
                    }
                }
            }
        });

        if ( count($newChapters) > 0 ) {
            Mail::to("reyhan.thee@icloud.com")->send(new NewChapters($newChapters));
        }
    }

    public function all_new_qidian_chapters_scraper() {
        $newChapters = array();

        Novel::where('status', 0)->where('group_id', 6)->whereNotNull('alternative_url')->whereHas('chapters', function($q) {
            $q->where('status', 0)->where('blacklist', 0);
        })->with(['chapters' => function($q) {
            $q->where('status', 0)->where('blacklist', 0)->orderBy('book')->orderBy('chapter');
        }])->orderBy('name', 'desc')->chunk(5, function ($novels) use (&$newChapters) {
            foreach ( $novels as $novel ) {
                if ( count($novel->chapters) > 0 ) {
                    foreach ( $novel->chapters as $item ) {
                        $chapter = $this->chapter_generator($item);

                        $description = "";
                        foreach ( $chapter as $c ) {
                            $description .= $c;
                        }

                        if ( str_word_count($description) > 250 ) {
                            $progress = $novel->no_of_chapters == 0 ? 0 : round(($item->chapter / $novel->no_of_chapters * 100), 2);

                            array_push($newChapters, array(
                                'novel' => $novel->name,
                                'label' => $item->label,
                                'chapter' => $item->chapter,
                                'book' => $item->book,
                                'progress' => number_format($progress, 2, ".", ",")
                            ));

                            echo $novel->name . " - " . $item->chapter . "\r\n";

                            $item->description = $description;

                            if ( trim($description) != "" ) {
                                $item->status = 1;
                            }
                            $item->download_date = Carbon::now();
                            $item->save();
                        } else {
//                            echo $item->novel->name . " - " . $item->chapter . " (Incomplete)\r\n";
                        }
                    }
                }
            }
        });

        if ( count($newChapters) > 0 ) {
            Mail::to("reyhan.thee@icloud.com")->send(new NewChapters($newChapters));
        }
    }

//    public function all_chapters_scraper() {
//        foreach ( $this->novelchapters->with('novel.group')->where('status', 1)->where('blacklist', 0)->whereRaw('char_length(description) < ?', [1300])->orderBy('novel_id')->orderBy('book')->orderBy('chapter')->get() as $item ) {
//            $chapter = $this->chapter_generator($item);
//
//            $description = "";
//            foreach ( $chapter as $c ) {
//                $description .= $c;
//            }
//
//            if ( str_word_count($description) > 250 ) {
//                $item->description = $description;
//
//                if ( trim($description) != "" ) {
//                    $item->status = 1;
//                }
//                $item->save();
//            } else {
//                $item->description = "";
//                $item->status = 0;
//                $item->save();
//
//                echo $item->novel->name . " " . $item->chapter . "\r\n";
//            }
//        };
//    }

    public function generate_chapter_file(Request $request) {
        if ( $request->has('id') ) {
            $array = json_decode($request->id);

            if ( count($array) > 0 ) {
                foreach ( $array as $id ) {
                    $object = $this->novelchapters->with('novel')->find($id);

                    $content = "<h1>" . $object->label . "</h1>" . $object->description;
                    $chapter = __generateHTMLChapter($content);
                    $path = '/' . $object->novel->id . '/Text/' . $object->id . '_chapter.xhtml';

                    if ( Storage::put($path, $chapter) ) {
                        $object->html_file = $path;
                        $object->save();
                    }
                }
            }
        }

        if ( $request->has('novel_id') ) {
            $object = Novel::with(['chapters' => function($q) {
                $q->where('blacklist', 0)->where('status', 1)->orderBy('book')->orderBy('chapter');
            }])->find($request->novel_id);

            $nav_path = '/Novel/' . $object->id . '/Text/nav.xhtml';
            $nav = __generateHTMLNav($object->chapters);

            Storage::put($nav_path, $nav);

            $toc_path = '/Novel/' . $object->id . '/toc.ncx';
            $toc = __generateHTMLToc($object);

            Storage::put($toc_path, $toc);

            $content_path = '/Novel/' . $object->id . '/content.opf';
            $content = __generateHTMLContent($object);

            Storage::put($content_path, $content);

            foreach ( $object->chapters as $c ) {
                $content = "<h1>" . $c->label . "</h1>" . $c->description;
                $chapter = __generateHTMLChapter($content);
                $path = '/Novel/' . $object->id . '/Text/' . $c->id . '_chapter.xhtml';

                if ( Storage::put($path, $chapter) ) {
                    $c->html_file = $path;
                    $c->save();
                }
            }
        } else {
            Novel::whereHas('chapters')->with(['chapters' => function($q) {
                $q->where('blacklist', 0)->where('status', 1)->orderBy('book')->orderBy('chapter');
            }])->chunk(5, function ($novels) {
                foreach ( $novels as $object ) {
//                    echo $object->name . "\r\n";

                    $nav_path = '/Novel/' . $object->id . '/Text/nav.xhtml';
                    $nav = __generateHTMLNav($object->chapters);

                    Storage::put($nav_path, $nav);

                    $toc_path = '/Novel/' . $object->id . '/toc.ncx';
                    $toc = __generateHTMLToc($object);

                    Storage::put($toc_path, $toc);

                    $content_path = '/Novel/' . $object->id . '/content.opf';
                    $content = __generateHTMLContent($object);

                    Storage::put($content_path, $content);

                    foreach ( $object->chapters as $c ) {
                        $content = "<h1>" . $c->label . "</h1>" . $c->description;
                        $chapter = __generateHTMLChapter($content);
                        $path = '/Novel/' . $object->id . '/Text/' . $c->id . '_chapter.xhtml';

                        if ( Storage::put($path, $chapter) ) {
                            $c->html_file = $path;
                            $c->save();
                        }
                    }
                }
            });
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $object = $this->novelchapters;

        if ( $request->has('novel_id') ) {
            $object->novel_id = $request->novel_id;
        }

        if ( $request->has('label') ) {
            $object->label = $request->label;
        }

        if ( $request->has('description') ) {
            $object->description = $request->description;
        }

        if ( $request->has('book') ) {
            $object->book = $request->book;
        }

        if ( $request->has('chapter') ) {
            $object->chapter = $request->chapter;
        }

        if ( $request->has('url') ) {
            $object->url = $request->url;
        }

        if ( $request->has('status') ) {
            $object->status = $request->status;
        }

        $object->save();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\NovelChapter  $novelChapter
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $object = $this->novelchapters->find($id);

        return response()->json($object);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\NovelChapter  $novelChapter
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $object = $this->novelchapters->find($id);

        if ( $request->has('label') ) {
            $object->label = $request->label;
        }

        if ( $request->has('description') ) {
            $object->description = $request->description;
        }

        if ( $request->has('book') ) {
            $object->book = $request->book;
        }

        if ( $request->has('chapter') ) {
            $object->chapter = $request->chapter;
        }

        if ( $request->has('url') ) {
            $object->url = $request->url;
        }

        if ( $request->has('double_chapter') ) {
            $object->double_chapter = $request->double_chapter;
        }

        if ( $request->has('status') ) {
            $object->status = $request->status;
        }

        $object->save();
    }

    public function blacklist($id)
    {
        $object = $this->novelchapters->find($id);
        $object->blacklist = 1;
        $object->save();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\NovelChapter  $novelChapter
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $object = $this->novelchapters->find($id);
        $object->delete();
    }

    public function missing_chapters($id) {
        $object = Novel::find($id);
        $latestChapter = $this->novelchapters->where('novel_id', $id)->max('chapter');

        $chapterArray = array();
        $existingChapterArray = array();

        for ( $i = 1; $i <= $latestChapter; $i++ ) {
            array_push($chapterArray, $i);
        }

        foreach ( $this->novelchapters->where('novel_id', $id)->where('blacklist', 0)->get(['chapter', 'double_chapter']) as $item ) {
            array_push($existingChapterArray, intval($item->chapter));

            if ( $item->double_chapter == 1 ) {
                array_push($existingChapterArray, intval($item->chapter) + 1);
            }
        }

        $missingChapters = array_diff($chapterArray, $existingChapterArray);

        foreach ( $missingChapters as $item ) {
            $chapter_object = new NovelChapter;
            $chapter_object->label = "Chapter " . $item;
            $chapter_object->chapter = $item;

            if ( $object->group_id == 9 ) {
                $chapter_object->url = $object->chapter_url . $item . "/" . str_replace(" ", "-", $object->name);
            } else if ( $object->group_id == 19 ) {
                $chapter_object->url = $object->chapter_url . $item . ".html";
            } else {
                $chapter_object->url = $object->chapter_url . $item;
            }
            $chapter_object->novel_id = $id;

            $chapter_object->save();
        }
    }

    public function delete_all_chapters($id) {
        foreach ($this->novelchapters->where('novel_id', $id)->get() as $item ) {
            $item->delete();
        }
    }
}

