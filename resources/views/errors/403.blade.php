@extends('errors.layout')
@section('code', '403')
@section('title', 'Acceso denegado')
{{-- Texto fijo: el mensaje de la excepcion no se muestra (podria contener datos internos). --}}
@section('message', 'No tienes permiso para ver esta sección.')
