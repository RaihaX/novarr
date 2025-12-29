/**
 * First we will load all of this project's JavaScript dependencies which
 * includes Bootstrap and other libraries. It is a great starting point when
 * building robust, powerful web applications using Laravel.
 */

import './bootstrap';
import 'gasparesganga-jquery-loading-overlay';

$.ajaxSetup({
    headers: {
        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
    }
});

$(document).ajaxStart(function(){
    $.LoadingOverlay("show");
});

$(document).ajaxStop(function(){
    $.LoadingOverlay("hide");
});
