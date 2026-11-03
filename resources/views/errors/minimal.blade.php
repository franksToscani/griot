{{--
    The page of the other codes (401, 402 and those without a view of their own): Laravel's views
    give the title; the message is a general one, with the way back home.
--}}
@extends('errors::layout')

@section('message')
    {{ __('errors.generic.message') }}
@overwrite
@section('actions')
    @include('errors::actions', ['links' => ['home']])
@overwrite
