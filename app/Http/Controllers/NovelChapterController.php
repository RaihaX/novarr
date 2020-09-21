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

    public function novel_scraper($id)
    {
        $object = Novel::find($id);
        $novel_id = $object->id;

        $toc = __tableOfContentGenerator($object);

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

        $chapter = __chapterGenerator($object);

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
            $chapter = __chapterGenerator($item);

            if ( $item->novel->group->id == 6 ) {
                $description = "";
                foreach ($chapter as $c) {
                    $description .= $c;
                }

                if (str_word_count($description) > 250) {
                    $item->description = $description;

                    if (trim($description) != "") {
                        $item->status = 1;
                    }
                    $item->download_date = Carbon::now();
                    $item->save();
                }
            } else if ( $item->novel->group->id == 39 ) {
                $description = "";
                foreach ($chapter as $c) {
                    $description .= $c;
                }

                $item->description = $description;

                if (trim($description) != "") {
                    $item->status = 1;
                }
                $item->download_date = Carbon::now();
                    $item->save();
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
                        $chapter = __chapterGenerator($item);

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

