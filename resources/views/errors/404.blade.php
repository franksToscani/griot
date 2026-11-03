@extends('errors::layout')

@section('title', __('errors.404.title'))
@section('message', __('errors.404.message'))
@section('actions')
    @include('errors::actions', ['links' => ['home', 'providers']])
@endsection
