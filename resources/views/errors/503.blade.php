@extends('errors::layout')

{{-- Also the page of php artisan down: rendered once and saved, so it must stand on its own. --}}
@section('title', __('errors.503.title'))
@section('message', __('errors.503.message'))
@section('actions')
    @include('errors::actions', ['links' => ['retry']])
@endsection
