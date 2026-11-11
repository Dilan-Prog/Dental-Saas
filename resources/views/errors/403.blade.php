@extends('errors.layout')
@section('code', '403')
@section('title', 'Acceso denegado')
@section('message', $exception->getMessage() ?: 'No tienes permiso para ver esta sección.')
