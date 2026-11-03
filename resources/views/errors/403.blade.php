@extends('errors::layout')

@section('title', __('errors.403.title'))
@section('message', __('errors.403.message'))
@section('actions')
    @include('errors::actions', ['links' => ['home', 'providers']])
@endsection
