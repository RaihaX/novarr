@extends('layouts.app')

@section('title', "Novels")

@section('subnav')
    <a id="novelCreate" class="text-light" href="javascript:void(0);">
        {{ __("Add New") }}
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="input-group mb-3">
                        <div class="input-group-prepend">
                            <span class="input-group-text" id="basic-addon1"><i class="fa fa-search"></i></span>
                        </div>
                        <input type="text" class="form-control" placeholder="Search for a novel..." id="searchInput" aria-label="Username" aria-describedby="basic-addon1">
                    </div>
                </div>
                <div id="searchContent">

                </div>
            </div>
        </div>
    </div>
@endsection

@section('javascript')
    <script type="text/javascript">
        $(function() {
            let timer;

            $("#searchInput").on('keyup', function() {
                clearTimeout(timer)
                let z = this;

                timer = setTimeout(function() {
                    searchNovel($(z).val());
                }, 500);
            });
        });

        function searchNovel(novel) {
            $.ajax({
                type: "POST",
                url: "/novels/search/novelupdates",
                data: {
                    name: novel,
                }
            }).done(function(d) {
                let content = $("#searchContent");
                content.empty();

                $.each(d, function(a, b) {
                    let card = $("<div>").addClass("card");
                    let cardBody = $("<div>").addClass("card-body");
                    let p = $("<p>").html(b.name);

                    cardBody.append(p);
                    card.append(cardBody);
                    content.append(card);
                });
            });
        }
    </script>
@endsection