<?php

namespace App\Http\Controllers;

use App\Group;
use App\Novel;
use App\NovelChapter;
use Illuminate\Http\Request;

use DataTables;
use Feeds;

class GroupController extends Controller
{
    /**
     * The novel repository instance.
     */
    protected $groups;

    /**
     * Create a new controller instance.
     *
     * @param  UserRepository  $users
     * @return void
     */
    public function __construct(Group $groups)
    {
        $this->groups = $groups;
    }

    public function datatables() {
        return DataTables::of($this->groups->orderBy('label')->get())->toJson();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $object = $this->groups->orderBy('label')->get();

        return view('groups.index', ['data' => $object]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $object = $this->groups;

        if ( $request->has('label') ) {
            $object->label = $request->label;
        }

        if ( $request->has('url') ) {
            $object->url = $request->url;
        }

        if ( $request->has('rss') ) {
            $object->rss = $request->rss;
        }

        $object->save();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Novel  $novel
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Novel  $novel
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $object = $this->groups->find($id);

        if ( $request->has('label') ) {
            $object->label = $request->label;
        }

        if ( $request->has('url') ) {
            $object->url = $request->url;
        }

        if ( $request->has('rss') ) {
            $object->rss = $request->rss;
        }

        $object->save();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Novel  $novel
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $object = $this->groups->find($id);
        $object->delete();
    }

    public function rss_feed() {
        $result = array();

        foreach ( $this->groups->whereNotNull('rss')->get() as $g ) {
            $feed = Feeds::make($g->rss);

            switch ($g->id) {
                case 1:
                    foreach ( $feed->get_items() as $item ) {
                        $title = explode("-", $item->get_title());

                        array_push($result, array(
                            "novel" => $title[0],
                            "label" => $title[1],
                            "url" => $item->get_permalink()
                        ));
                    }
                    break;
//                case 6:
//                    foreach ( $feed->get_items() as $item ) {
//                        $titleArr = explode("/", str_replace("https://www.webnovel.com/rssbook/", "", $item->get_permalink()));
//
//                        $novel = Novel::where('unique_id', $titleArr[0])->first();
//
//                        if ( !empty($novel) ) {
//                            array_push($result, array(
//                                "novel" => $novel->name,
//                                "label" => $item->get_title(),
//                                "url" => $item->get_permalink()
//                            ));
//                        }
//                    }
//                    break;
            }

        }

        if ( count($result) > 0 ) {
            foreach ( $result as $item ) {
                $novel = Novel::where('name', $item["novel"])->first();

                if ( !empty($novel) ) {
                    $data = __tocChapterLabelGenerator($item["label"], $item["url"]);

                    if ( $novel->group_id == 6 ) {
                        $check_duplicate = NovelChapter::where('novel_id', $novel->id)->where('chapter', round($data["chapter"], 2))->select('id')->first();
                    } else {
                        $check_duplicate = NovelChapter::where('novel_id', $novel->id)->where('chapter', round($data["chapter"], 2))->where('book', intval($data["book"]))->select('id')->first();
                    }

                    if (empty($check_duplicate)) {
                        if (!empty($data["label"]) && !empty($data["url"]) && !empty($data["chapter"])) {
                            echo var_dump($data);

                            $object = new NovelChapter();
                            $object->novel_id = $novel->id;
                            $object->label = $data["label"];
                            $object->url = $data["url"];
                            $object->chapter = $data["chapter"];
                            $object->book = intval($data["book"]);
                            $object->save();
                        }
                    }
                }
            }
        }
    }
}
