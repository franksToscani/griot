@extends('errors::layout')

{{-- The page stayed open too long (issue #130): what was written may not have been sent. --}}
@section('title', __('errors.419.title'))
@section('message', __('errors.419.message'))
@section('actions')
    @include('errors::actions', ['links' => ['reload', 'home']])
@endsection
