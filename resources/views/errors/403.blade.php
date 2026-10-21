@extends('errors.layout', [
    'code' => '403',
    'icon' => 'shield',
    'title' => __('That page is not yours to open'),
    'message' => __('Sign in with the right account, or head back home.'),
])
