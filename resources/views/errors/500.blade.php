@extends('errors::layout')

{{-- An apology and a way to try again: no promise of a time, never a detail of the error. --}}
@section('title', __('errors.500.title'))
@section('message', __('errors.500.message'))
@section('actions')
    @include('errors::actions', ['links' => ['retry', 'home']])
@endsection
