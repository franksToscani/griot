@extends('errors::layout')

@section('title', __('errors.429.title'))
@section('message', __('errors.429.message'))
@section('actions')
    @include('errors::actions', ['links' => ['retry_later', 'home']])
@endsection
