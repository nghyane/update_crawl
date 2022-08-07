@extends('themes.kome.layouts.full')
@section('title', L::_("Register") . ' - ' .getConf('meta')['site_name'])
@section('description', getConf('meta')['home_description'])

@section('url', url('register'))

@section("head-css")
    <link href="/kome/assets/css/user.css" rel="stylesheet" type="text/css"/>
@stop

@section("content")
    <div class="fed-part-case" id="main-content">
        <div class="wrap-content-part">
            <div class="header-content-part">
                <div class="title text-center"
                     style="width: 100%; font-size: 1.3rem !important;">{{ L::_("Register") }}</div>
            </div>
            <div class="body-content-part" style="min-height:700px;">
                <form id="login-form" action="/ajax/auth/register" method="POST">
                    <div class="wrap-form-input">
                        <div class="wrap-input">
                            <div class="wr-input">
                                <input autofocus="" id="name" name="name"
                                       placeholder="Your Name" type="text"></div>
                        </div>
                        <div class="wrap-input">
                            <div class="wr-input">
                                <input autofocus="" id="email" name="email"
                                       placeholder="Email address" type="text"></div>
                        </div>
                        <div class="wrap-input">
                            <div class="wr-input">
                                <input id="password" name="password" placeholder="Password"
                                       type="password">
                            </div>
                        </div>
                        <div class="wrap-input">
                            <div class="wr-input">
                                <input id="cf_password" name="cf_password" placeholder="Confim Password"
                                       type="password">
                            </div>
                            <br class="clear">
                        </div>
                        <div class="wrap-input">
                            <div class="wr-input">
                                <button class="btn btn-secondary" type="submit">{{ L::_("Submit") }}</button>
                                <label class="checkbox">
                                    <input id="isRemember" name="isRemember" type="checkbox"
                                           value="2">{{ L::_("Remember me") }}</label></div>
                            <div class="wr-input">
                                <a class="pull-left"
                                   href="{{ url("login") }}">{{ L::_("Back To Login") }}</a>
                                <br class="clear"></div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

@stop

@section("body-js")
    <script src="/kome/assets/js/user.js" type="text/javascript"></script>

    <script type="text/javascript">
        $(document.documentElement).ready(function () {
            ajaxSubmit("#login-form");
        });

    </script>

@stop