<?php

namespace App\Http\Controllers;

use App\Language;
use Illuminate\Http\Request;

use DataTables;

class LanguageController extends Controller
{
    /**
     * The novel repository instance.
     */
    protected $languages;

    /**
     * Create a new controller instance.
     *
     * @param  UserRepository  $users
     * @return void
     */
    public function __construct(Language $languages)
    {
        $this->languages = $languages;
    }

    public function datatables() {
        return DataTables::of($this->languages->orderBy('label')->get())->toJson();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('languages.index');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $object = $this->languages;

        if ( $request->has('label') ) {
            $object->label = $request->label;
        }

        if ( $request->has('short') ) {
            $object->short = $request->short;
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
        $object = $this->languages->find($id);

        if ( $request->has('label') ) {
            $object->label = $request->label;
        }

        if ( $request->has('short') ) {
            $object->short = $request->short;
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
        $object = $this->languages->find($id);
        $object->delete();
    }
}
